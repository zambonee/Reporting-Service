<?php

// Requires $database to be passed in.
// Returns the ODBC connection.
function connect($database) {
    $database = strtoupper($database);
    if ($database != "NMML_AEP_SSL" && $database != "NMML_AEP_NFS") {
        die(print("Cannot connect to database '$database'."));
    }
    $server = "servername";
    $user = "username";
    $password = "password";
    $conn = odbc_connect("Driver={SQL Server};Server=$server;Database=$database;", $user, $password);
    if ($conn === false) {
        die(print_r(odbc_errormsg($conn)));
    }
    return $conn;
}

?>