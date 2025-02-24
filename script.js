function logMessage(msg) {
    document.getElementById('log').innerText += "\n" + msg;
}

function insertTestProduct() {
    fetch('server.php?action=insertTest')
        .then(response => response.json())
        .then(data => logMessage(JSON.stringify(data, null, 2)))
        .catch(error => logMessage("Error: " + error));
}

function importProducts() {
    fetch('server.php?action=importCSV')
        .then(response => response.json())
        .then(data => logMessage(JSON.stringify(data, null, 2)))
        .catch(error => logMessage("Error: " + error));
}

function insertSingleProduct() {
    fetch('server.php?action=insertSingleProduct')
        .then(response => response.json())
        .then(data => logMessage(JSON.stringify(data, null, 2)))
        .catch(error => logMessage("Error: " + error));
}

