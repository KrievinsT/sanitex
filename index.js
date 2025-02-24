const SftpClient = require('ssh2-sftp-client');
const csv = require('csv-parser');
const fs = require('fs');
const path = require('path');
const { parse } = require('json2csv');
require('dotenv').config();

const coefficient = parseFloat(process.argv[3]) || parseFloat(process.env.COEFFICIENT);

const config = {
  host: process.env.SFTP_HOST,
  port: process.env.SFTP_PORT,
  username: process.env.SFTP_USER,
  password: process.env.SFTP_PASS,
  coefficient: coefficient
};

const SFTP_TIMEOUT = 20000;
const HANDLE_FILE = 'data/handles.json';

const uploadsFolder = path.join(__dirname, 'uploads');

fs.readdir(uploadsFolder, (err, files) => {
    if (err) {
        console.error('Error reading the uploads directory:', err);
        return;
    }

    files.forEach(file => {
        const filePath = path.join(uploadsFolder, file);
        fs.unlink(filePath, (err) => {
            if (err) {
                console.error(`Error deleting file ${file}:`, err);
            } else {
                console.log(`Deleted file: ${file}`);
            }
        });
    });
});


async function downloadLatestFiles(sftp) {

  

  const remoteDir = '/'; 
  const filePatterns = {
    productInfo: /MKANDERSONS_ProductInfo_\d{4}-\d{2}-\d{2}\.csv/,
    products: /MKANDERSONS_Products_\d{4}-\d{2}-\d{2}\.csv/,
    stock: /MKANDERSONS_Stock_\d{4}-\d{2}-\d{2}_\d{4}\.csv/
  };

  // List all files in the remote directory
  const files = await sftp.list(remoteDir);

  // Find the latest files for each type
  const latestFiles = {
    productInfo: findLatestFile(files, filePatterns.productInfo),
    products: findLatestFile(files, filePatterns.products),
    stock: findLatestFile(files, filePatterns.stock)
  };

  if (!latestFiles.productInfo || !latestFiles.products || !latestFiles.stock) {
    throw new Error("One or more required CSV files are missing from the SFTP server.");
  }

  // Download the latest files
  await sftp.get(`${remoteDir}/${latestFiles.productInfo.name}`, 'uploads/ProductInfo.csv');
  await sftp.get(`${remoteDir}/${latestFiles.products.name}`, 'uploads/Products.csv');
  await sftp.get(`${remoteDir}/${latestFiles.stock.name}`, 'uploads/Stock.csv');

  console.log('Latest files downloaded successfully');
}

function findLatestFile(files, pattern) {
  const matchingFiles = files.filter(file => pattern.test(file.name));

  if (matchingFiles.length === 0) {
    return null; // No matching files found
  }

  // Sort files by date (newest first)
  matchingFiles.sort((a, b) => new Date(b.name.match(/\d{4}-\d{2}-\d{2}/)[0]) - new Date(a.name.match(/\d{4}-\d{2}-\d{2}/)[0]));

  return matchingFiles[0];
}


async function main(outputFilename) {
  const sftp = new SftpClient();
  
  try {
    const connectPromise = sftp.connect(config);
    const timeoutPromise = new Promise((_, reject) => {
      setTimeout(() => {
        reject(new Error('SFTP connection timed out. Please check your network or server configuration.'));
      }, SFTP_TIMEOUT);
    });

    await Promise.race([connectPromise, timeoutPromise]);
    console.log('SFTP connected successfully');

    await downloadLatestFiles(sftp);
    const products = await processData();
    const csvOutput = generateCSV(products);
    await uploadCSV(sftp, csvOutput, outputFilename);
    
  } catch (err) {
    console.error('Error:', err.message);
    process.stdout.write(`Error: ${err.message}`);
    process.exit(1);
  } finally {
    await sftp.end();
  }
}

async function processData() {
  const products = {};
  const handles = loadHandles(); // Load existing handles

  await new Promise((resolve) => {
    fs.createReadStream('data/ProductInfo.csv')
      .pipe(csv({ separator: ';' }))
      .on('data', (row) => {
        const productId = row.INF_PREK;
        
        // Ensure unique and persistent handles
        if (!handles[productId] || !handles[productId].startsWith('uid-')) {
          handles[productId] = `uid-${Date.now()}${Math.floor(Math.random() * 1000)}`; // Unique handle
        }

        products[productId] = {  
          Handle: handles[productId],
          Title: row.Name,
          Description: row.Description,
          Photo: row.Photo_URL, 
          Category: row["Item category"],
          Language: 'lv',
          "Variant No": '',
          "Option Name 1": '',
          "Option Value 1": '',
          "Option Name 2": '',
          "Option Value 2": '',
          "Option Name 3": '',
          "Option Value 3": '',
          SKU: '',
          Visible: "TRUE",
          Featured: "FALSE",
          Tax: '',
          Vendor: '',
          Model: ''
        };
      })
      .on('end', resolve);
  });

  await new Promise((resolve) => {
    fs.createReadStream('data/Products.csv')
      .pipe(csv({ separator: ';' }))
      .on('data', (row) => {
        if (products[row.INF_PREK]) {
          products[row.INF_PREK].Price = parseFloat(row.Kaina);
          products[row.INF_PREK]['Sale Price'] = parseFloat(row.Kaina) * config.coefficient;
        }
      })
      .on('end', resolve);
  });

  await new Promise((resolve) => {
    fs.createReadStream('data/Stock.csv')
      .pipe(csv({ separator: ';' }))
      .on('data', (row) => {
        if (products[row.INF_PREK]) {
          products[row.INF_PREK].Stock = Number.isInteger(parseInt(row.PC1)) ? parseInt(row.PC1) : 0;
        }
      })
      .on('end', resolve);
  });

  // Save handles for persistence
  saveHandles(handles);

  return Object.values(products).filter(product => 
    ['kafijas, kakao, kapučīno, tējas', 'kafijas kapsulas']
    .includes(product.Category.toLowerCase())
  );
}

function generateCSV(products) {
  const fields = ['Handle', 'Language', 'Category', 'Title', 'Description', 'Variant No', 'Option Name 1', 'Option Value 1','Option Name 2', 'Option Value 2', 'Option Name 3', 'Option Value 3', 'Price', 'Sale Price', 'SKU', 'Stock', 'Visible', 'Featured', 'Tax', 'Vendor', 'Model'];
  const opts = { fields };

  try {
    return parse(products, opts);
  } catch (err) {
    console.error(err);
  }
}

function loadHandles() {
  if (fs.existsSync(HANDLE_FILE)) {
    try {
      return JSON.parse(fs.readFileSync(HANDLE_FILE, 'utf-8'));
    } catch (error) {
      console.error('Error reading handles file:', error);
      return {};
    }
  }
  return {};
}

function saveHandles(handles) {
  try {
    fs.writeFileSync(HANDLE_FILE, JSON.stringify(handles, null, 2), 'utf-8');
  } catch (error) {
    console.error('Error saving handles file:', error);
  }
}

async function uploadCSV(sftp, csvOutput, outputFilename) {
  fs.writeFileSync(outputFilename, csvOutput);
  console.log('CSV file generated successfully');
}

const outputFilename = process.argv[2] || `output/Products_${new Date().toISOString().slice(0,10).replace(/-/g, '')}.csv`;
main(outputFilename);
