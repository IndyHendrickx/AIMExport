<?php

// Debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Import XLSX maker
require 'vendor/autoload.php';

// No timeouts!!!
ini_set('max_execution_time', 0);

// Creating the connection by specifying the connection details
$db = mysqli_connect("localhost", "root", "root", "export");

// Checking the  connection
if (!$db) {

    // Connection error return data
    ReturnData(array(
        "status" => "420",
        "error" => "No DB connection - are you sure the service is running?",
        "data" => ""
    ), $db);

}

// Importing CSV data for each table
SetFKAndUniqueCheck(0, $db);
$tables = array(
    "employees" => "(
        ID, 
        name
    )",

    "projects" => "(
        @ID_str, 
        name
    ) SET
        ID = CAST(@ID_str AS UNSIGNED INTEGER)", 
    
    "tasks" => "(
        @ID_str, 
        name
    ) SET
        ID = CAST(@ID_str AS UNSIGNED INTEGER)", 
    
    "registrations" => "(
        @ID_str,
        @taskID_str,
        @projectID_str,
        employeeID,
        monthAndYear,
        month,
        @totalHoursAsNumber_str,
        @employeeCost_str,
        @generalCost_str,
        overwriteProject,
        overwriteTask
    ) SET 
        ID = CAST(@ID_str AS UNSIGNED INTEGER), 
        taskID = CAST(@taskID_str AS UNSIGNED INTEGER), 
        projectID = CAST(@projectID_str AS UNSIGNED INTEGER), 
        totalHoursAsNumber = CAST(@totalHoursAsNumber_str AS DOUBLE),
        employeeCost = CAST(@employeeCost_str AS DOUBLE),
        generalCost = CAST(@generalCost_str AS DOUBLE)" 
);

array_walk($tables, "ImportData", $db);
SetFKAndUniqueCheck(1, $db);

// Get records for export
$registrations = $db->query(
    "SELECT 
    COALESCE(
        CASE WHEN r.overwriteProject IS NOT NULL AND TRIM(r.overwriteProject) <> '' THEN r.overwriteProject ELSE NULL END,
        p.name
    ) AS Project,
    
    e.name AS Employee,
    
    COALESCE(
        CASE WHEN r.overwriteTask IS NOT NULL AND TRIM(r.overwriteTask) <> '' THEN r.overwriteTask ELSE NULL END,
        t.name
    ) AS Task,
    
    r.monthAndYear AS Date,
    r.month AS Month,
    
    SUM(r.totalHoursAsNumber) AS SumHours,
    SUM(r.employeeCost + r.generalCost) AS SumCost
    
FROM
    registrations r
JOIN 
    projects p ON r.projectID = p.ID
JOIN 
    employees e ON r.employeeID = e.ID
JOIN 
    tasks t ON r.taskID = t.ID
    
GROUP BY 
    COALESCE(
        CASE WHEN r.overwriteProject IS NOT NULL AND TRIM(r.overwriteProject) <> '' THEN r.overwriteProject ELSE NULL END,
        p.name
    ),
    
    e.name, 
    
    COALESCE(
        CASE WHEN r.overwriteTask IS NOT NULL AND TRIM(r.overwriteTask) <> '' THEN r.overwriteTask ELSE NULL END,
        t.name
    ),
    
    r.monthAndYear,
    r.month
    
ORDER BY 
    COALESCE(
        CASE WHEN r.overwriteProject IS NOT NULL AND TRIM(r.overwriteProject) <> '' THEN r.overwriteProject ELSE NULL END,
        p.name
    ), 
    e.name, 
    COALESCE(
        CASE WHEN r.overwriteTask IS NOT NULL AND TRIM(r.overwriteTask) <> '' THEN r.overwriteTask ELSE NULL END,
        t.name
    ),
    r.monthAndYear, 
    r.month

    "
);

// Check if registrations
if(!$registrations){
    ReturnData(array(
        "status" => "420",
        "error" => "There are no registrations generated.",
        "data" => ""
    ), $db);
}

// Create a new Spreadsheet
$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set column names as headers
$columns = array();
$i = 0;
while ($fieldInfo = $registrations->fetch_field()) {
    $i++;
    $sheet->setCellValueByColumnAndRow($i, 1, $fieldInfo->name);
    $columns[] = $fieldInfo->name;
}

// start from the second row for row-data
$rowNumber = 2; 
while ($row = $registrations->fetch_assoc()) {
    $columnNumber = 0;
    foreach ($columns as $columnName) {
        $columnNumber++;
        $sheet->setCellValueByColumnAndRow($columnNumber, $rowNumber, $row[$columnName]);
    }
    $rowNumber++;
}

// Generate xlsx from the Spreadsheet
$writer = \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, 'Xlsx');
ob_start();
$writer->save('php://output');
$excelOutput = ob_get_clean();

// Convert to base64
$base64Excel = base64_encode($excelOutput);

// Get data
ReturnData(array(
    "status" => "200",
    "error" => "",
    "data" => $base64Excel
), $db);

// Sets FK and unique checks
function SetFKAndUniqueCheck(int $set, mysqli|false $db){
    $db->query("SET foreign_key_checks = $set");
    $db->query("SET foreign_key_checks = $set");
}

// Imports data from csv into DB
function ImportData(string $columns, string $table, mysqli|false $db){

    // Importing x...
    $db->query("DELETE FROM $table");
    $query = "LOAD DATA INFILE '$table.csv' IGNORE INTO TABLE $table FIELDS TERMINATED BY ',' ENCLOSED BY '\"' LINES TERMINATED BY '\r\n'";
    $result = $db->query($query);

    // Check if x import was success
    if(!$result){
        ReturnData(array(
            "status" => "420",
            "error" => "Could not import $table",
            "data" => ""
        ), $db);
    }
}

// Returns the data from the script as JSON
function ReturnData(array $data, mysqli|false $db){

    // Close DB is open
    if($db){
        $db->close();
    }

    // Set header and print response
    header("Content-Type: application/json");
    echo json_encode($data);

    // Exit PHP
    exit();
}
