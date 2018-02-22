<?php

// Returns a file of user-defined type ($post->fileType): xlsx, r, or txt (default).

$post = json_decode(file_get_contents('php://input'));
include_once "parsequery.php";
include_once "connection.php";
$query = "";
$database = "";
// database and query will be different $post properties depending on whether the user submitted their query from a text-console.
if (isset($post->useConsole) && $post->useConsole) {
    $query = $post->consoleQuery;
    $database = $post->consoleDatabase;
}
else {
    $query = parsequery($post, false);
    $database = $post->collectionTable[0]->container->name;
}
$conn = connect($database);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
   throw new ErrorException($errno, $errno, 0, $errfile, $errline);
});
try {
    $result = odbc_exec($conn, $query);
}
catch (Exception $ex) {
    echo odbc_errormsg($conn);
}
// Excel file type
if ($post->fileType == "xlsx") {
    // Using a deprecated library because PHPSpreadsheet requires Composer for the PHP server, which I cannot install. I may be able to get around it by creating my own vender/autoload.php file on my home computer.
    require_once "/../phpexcel/PHPExcel.php";
    $objPHPExcel = new PHPExcel();
    $objPHPExcel->getProperties()->setCreator("AEP Reporting Service")
        ->setLastModifiedBy("AEP Reporting Service")
        ->setTitle("Query Result")
        ->setDescription("Query result automatically generated with the AEP Reporting Service.");
    for ($i = 1; $i <= odbc_num_fields($result); $i++) {
        $objPHPExcel->getActiveSheet()->setCellValueByColumnAndRow($i - 1, 1, odbc_field_name($result, $i));
    }
    $rowNumber = 2;
    while ($row = odbc_fetch_array($result))
    {
        // Dis-associate $row so I can work with the column index rather than the column name.
        $values = array_values($row);
        foreach ($values as $i => $v) {
            if ($v != null) {
                // Get the Excel-friendly coordinates for setCellValueExplicit. Do not use setCellValueByColumnAndRow() because it can create some Excel read errors when a cell begins with '='.
                $cell = PHPExcel_Cell::stringFromColumnIndex($i) . $rowNumber;
                $type = odbc_field_type($result, $i);
                $format = "s";
                $formatCode;
                // odbc_field_type() is returning a lot of nvarchars when it should not be, so cannot format many data types.
                // To do: identify all data types and format to be more Excel-readable.
                // To do: set formatCode.
                switch ($type) {
                    case "bit":
                    case "bigint":
                    case "numeric":
                    case "smallint":
                    case "decimal":
                    case "smallmoney":
                    case "int":
                    case "tinyint":
                    case "money":
                    case "float":
                    case "real":
                        $format = "n";
                        break;
                    case "date":
                        break;
                    case "datetime":
                    case "datetime2":
                    case "datetimeoffset":
                    case "smalldatetime":
                        break;
                    case "time":
                        break;
                    default: 
                        $format = "s";
                        break;
                }
                $objPHPExcel->getActiveSheet()->setCellValueExplicit($cell, $v, $format);
                if (!empty($formatCode)) {
                    $objPHPExcel->getActiveSheet()->getStyle($cell)->getNumberFormat()->setFormatCode($formatCode);
                }
            }
        }
        $rowNumber++;
    }    
    $objPHPExcel->getActiveSheet->setTitle("data");
    $objPHPExcel->setActiveSheetIndex(0);
    $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
    $objWriter->setPreCalculateFormulas(false);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment');
    $objWriter->save('php://output');
}
// R script
else if ($post->fileType == "r") {
    header("Content-type: text/r");
    header("Content-disposition: attachement");
    echo "### Install library if you have not done so already ###\r\n";
    echo "if (!require(\"RODBC\")) {\r\n";
    echo "\tinstall.packages(\"RODBC\")\r\n";
    echo "\trequire(\"RODBC\")\r\n";
    echo "}\r\n";
    echo "### Contact your DB manager to get these values ###\r\n";
    echo "server <- readline(prompt = \"Server name: \")\r\n";
    echo "database <- readline(prompt = \"Database name: \")\r\n";
    echo "authentication <- \";trusted_connection=true\";\r\n";
    echo "### Most likely, you will be using a Trusted Connection so you do not have to supply a user name and password ###\r\n";
    echo "trustedConnection <- menu(c(\"Yes\", \"No\"), title = \"Is this a trusted connection?\")\r\n";
    echo "if (trustedConnection != 1)\r\n";
    echo "{\r\n";
    echo "\tusername <- readline(prompt = \"User name: \")\r\n";
    echo "\tpassword <- readline(prompt = \"Password (Warning! your password will show on screen): \")\r\n";
    echo "\tauthentication <- paste0(\";uid=\", username, \";pwd=\", password)\r\n";
    echo "}\r\n";
    echo "onnectionString <- paste0(\"driver={SQL Server};server=\", server, \";database=\", database, authentication)\r\n";
    echo "channel <- odbcDriverConnect(connectionString)\r\n";
    echo "query <- \"$query\"\r\n";    
    echo "result <- sqlQuery(channel, query)\r\n";
    echo "odbcClose(channel)\r\n";
    echo "### Do stuff with \"result\" ###";
}
// Tab-separated file type
else {
    header("Content-type: text/txt");
    header("Content-disposition: attachment");
    for ($i = 1; $i <= odbc_num_fields($result); $i++) {
        echo str_replace("\t", " ", odbc_field_name($result, $i));
        echo "\t";
    }
    echo "\r\n";
    while ($row = odbc_fetch_array($result))
    {
        foreach ($row as $key => $value)
        {
            echo str_replace("\r\n", " ", str_replace("\t", " ", $value));
            echo "\t";
        }
        echo "\r\n";
    }
}
exit();