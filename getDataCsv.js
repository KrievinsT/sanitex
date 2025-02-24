const fs = require('fs');
const path = require('path');
const SftpClient = require('ssh2-sftp-client'); // Assuming you're using ssh2-sftp-client

const coefficient = parseFloat(process.argv[3]) || parseFloat(process.env.COEFFICIENT);

const config = {
  host: process.env.SFTP_HOST,
  port: process.env.SFTP_PORT,
  username: process.env.SFTP_USER,
  password: process.env.SFTP_PASS,
  coefficient: coefficient
};

const SFTP_TIMEOUT = 20000;

async function deleteAllFilesInUploadsFolder() {
  const uploadsDir = path.join(__dirname, 'uploads');

  try {
    const files = await fs.promises.readdir(uploadsDir);

    for (const file of files) {
      const filePath = path.join(uploadsDir, file);
      await fs.promises.unlink(filePath);
      console.log(`Deleted file: ${filePath}`);
    }

    console.log('All files in uploads folder have been deleted.');
  } catch (err) {
    if (err.code === 'ENOENT') {
      console.log('Uploads folder does not exist, creating it now.');
      await fs.promises.mkdir(uploadsDir, { recursive: true });
    } else {
      throw err;
    }
  }
}

async function downloadLatestFiles(sftp) {
  const remoteDir = '/'; 
  const filePatterns = {
    productInfo: /MKANDERSONS_ProductInfo_\d{4}-\d{2}-\d{2}\.csv/,
    products: /MKANDERSONS_Products_\d{4}-\d{2}-\d{2}\.csv/,
    stock: /MKANDERSONS_Stock_\d{4}-\d{2}-\d{2}_\d{4}\.csv/
  };

  const files = await sftp.list(remoteDir);

  const latestFiles = {
    productInfo: findLatestFile(files, filePatterns.productInfo),
    products: findLatestFile(files, filePatterns.products),
    stock: findLatestFile(files, filePatterns.stock)
  };

  if (!latestFiles.productInfo || !latestFiles.products || !latestFiles.stock) {
    throw new Error("One or more required CSV files are missing from the SFTP server.");
  }

  await sftp.get(`${remoteDir}/${latestFiles.productInfo.name}`, 'uploads/ProductInfo.csv');
  await sftp.get(`${remoteDir}/${latestFiles.products.name}`, 'uploads/Products.csv');
  await sftp.get(`${remoteDir}/${latestFiles.stock.name}`, 'uploads/Stock.csv');

  console.log('Latest files downloaded successfully');
}

function findLatestFile(files, pattern) {
  const matchingFiles = files.filter(file => pattern.test(file.name));

  if (matchingFiles.length === 0) {
    return null; 
  }

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

    await deleteAllFilesInUploadsFolder(); // Delete all files in the uploads folder before downloading new files
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

const outputFilename = process.argv[2] || `output/Products_${new Date().toISOString().slice(0,10).replace(/-/g, '')}.csv`;
main(outputFilename);