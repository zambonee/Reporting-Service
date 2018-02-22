<?php

// Returns the TOP 1000 rows from the submitted Query Builder query. Limited the output rows because of server settings and Google API processing time.
// Echos { truncated, query, result }, where truncated = true when there were more than 999 rows, query is the query string sent to the DB (mostly to assist development), and result is the Google API's Javascript Literal.

//ini_set('memory_limit', -1); //Tried to get all of the data returned, but did not work. Decided to only return TOP N rows because Google Charts becomes slow with too many rows.

set_error_handler(function($errno, $errstr, $errfile, $errline) {
   throw new ErrorException($errno, $errno, 0, $errfile, $errline);
});
// $_POST from AngularJS is empty, so have to file_get_contents()
$post = json_decode(file_get_contents('php://input'));
include_once "parsequery.php";
// Put connection.php in a more secure location for production.
include_once "connection.php";
// Restrict output to 10000 rows because 1- Google Charts would become slow, and 2- may not be able to build a literal JSON due to memory_limit.
$queryBuilder = parsequery($post, true, 1000);
$query = $queryBuilder["query"];
$database = $queryBuilder["database"];
$conn = connect($database);
$result = array("query" => $query);
try {
    $odbcResult = odbc_exec($conn, $query);
}
catch (Exception $ex) {
    echo odbc_errormsg($conn);
}
$result["truncated"] = odbc_num_rows($odbcResult) >= 1000;
// Convert to Google API's Javascript Literal.
/***************************** Format ********************************************
    {
     cols: [{id: 'task', label: 'Employee Name', type: 'string'},
            {id: 'startDate', label: 'Start Date', type: 'date'}],
     rows: [{c:[{v: 'Mike'}, {v: new Date(2008, 1, 28), f:'February 28, 2008'}]},
            {c:[{v: 'Bob'}, {v: new Date(2007, 5, 1)}]},
            {c:[{v: 'Alice'}, {v: new Date(2006, 7, 16)}]},
            {c:[{v: 'Frank'}, {v: new Date(2007, 11, 28)}]},
            {c:[{v: 'Floyd'}, {v: new Date(2005, 3, 13)}]},
            {c:[{v: 'Fritz'}, {v: new Date(2011, 6, 1)}]}
           ]
   }
*******************************************************************************
The type can be one of the following: 'string', 'number', 'boolean', 'date', 'datetime', and 'timeofday'.
*********************************************************************************/
$cols = array();
for ($i = 1; $i <= odbc_num_fields($odbcResult); $i++) {
    $label = odbc_field_name($odbcResult, $i);
    $type =  odbc_field_type($odbcResult, $i);
    // Convert from SQL Server to javascript data types.
    // None of the field types returns date, and passing a parameter to sp_descrive_first_results is throwing an error.
    switch ($type) {
        case "bit":
            $type = "boolean";
            break;
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
            $type = "number";
            break;
        case "date":
            $type = "date";
            break;
        case "datetime":
        case "datetime2":
        case "datetimeoffset":
        case "smalldatetime":
            $type = "datetime";
            break;
        case "time":
            $type = "timeofday";
            break;
        default:
            $type = "string";
            break;
    }
    array_push($cols, ["label" => $label, "type" => $type]);
}
$rows = array();
while ($row = odbc_fetch_array($odbcResult)) {
    $c = array();
    foreach ($row as $item) {
        $cleaned = $item;
        $type = $cols[count($c)]["type"];
        // Have to convert value to javascript datetime for Google Charts.
        if ($type == "datetime" || $type == "date" || $type == "timeofday") {
            $time = strtotime($item);
            $y = date('Y', $time);
            // Google Charts use a 0-indexed month.
            $M = intval(date('n', $time)) - 1;
            $d = date('j', $time);
            $h = date('G', $time);
            $m = date('i', $time);
            $s = date('s', $time);
            $cleaned = "Date($y, $M, $d, $h, $m, $s)";
        }
        array_push($c, ["v" => $cleaned]);
    }
    array_push($rows, ["c" => $c]);
}
$result["result"] = array("cols" => $cols, "rows" => $rows);
header("Content-Type: application/json; charset=UTF-8");
echo json_encode($result);
?>
