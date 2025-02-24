import fs from 'fs/promises';
import path from 'path';
import { fileURLToPath } from 'url';
import SftpClient from 'ssh2-sftp-client';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SFTP_TIMEOUT = 20000;
const coefficient = parseFloat(process.argv[3]) || parseFloat(process.env.COEFFICIENT);

const config = {
  host: process.env.SFTP_HOST,
  port: process.env.SFTP_PORT,
  username: process.env.SFTP_USER,
  password: process.env.SFTP_PASS,
  coefficient
};

async function clearUploadsFolder() {
  const uploadsDir = path.join(__dirname, 'uploads');
  try {
    const files = await fs.readdir(uploadsDir);
    await Promise.all(files.map(file => fs.unlink(path.join(uploadsDir, file))));
    console.log('Uploads folder cleared.');
  } catch (err) {
    if (err.code === 'ENOENT') {
      console.log('Uploads folder not found, creating it.');
      await fs.mkdir(uploadsDir, { recursive: true });
    } else {
      throw err;
    }
  }
}

async function downloadLatestFiles(sftp) {
  const remoteDir = '/';
  const patterns = {
    productInfo: /MKANDERSONS_ProductInfo_\d{4}-\d{2}-\d{2}\.csv/,
    products: /MKANDERSONS_Products_\d{4}-\d{2}-\d{2}\.csv/,
    stock: /MKANDERSONS_Stock_\d{4}-\d{2}-\d{2}_\d{4}\.csv/
  };

  const files = await sftp.list(remoteDir);
  const latestFiles = Object.fromEntries(
    Object.entries(patterns).map(([key, pattern]) => [key, findLatestFile(files, pattern)])
  );

  if (Object.values(latestFiles).some(file => !file)) {
    throw new Error('One or more required CSV files are missing.');
  }

  await Promise.all(
    Object.entries(latestFiles).map(([key, file]) => 
      sftp.get(`${remoteDir}/${file.name}`, path.join(__dirname, `uploads/${key}.csv`))
    )
  );
  console.log('Latest files downloaded.');
}

function findLatestFile(files, pattern) {
  return files
    .filter(file => pattern.test(file.name))
    .sort((a, b) => new Date(b.name.match(/\d{4}-\d{2}-\d{2}/)[0]) - new Date(a.name.match(/\d{4}-\d{2}-\d{2}/)[0]))[0] || null;
}

async function main() {
  const sftp = new SftpClient();
  try {
    await Promise.race([
      sftp.connect(config),
      new Promise((_, reject) => setTimeout(() => reject(new Error('SFTP connection timed out.')), SFTP_TIMEOUT))
    ]);
    console.log('SFTP connected.');

    await clearUploadsFolder();
    await downloadLatestFiles(sftp);
    const products = await processData();
    const csvOutput = generateCSV(products);
    await uploadCSV(sftp, csvOutput, outputFilename);
  } catch (err) {
    console.error('Error:', err.message);
    process.exit(1);
  } finally {
    await sftp.end();
  }
}

const outputFilename = process.argv[2] || `output/Products_${new Date().toISOString().slice(0,10).replace(/-/g, '')}.csv`;
main(outputFilename);