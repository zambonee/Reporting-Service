<?php

// All tables are included in this http request. This takes a few minutes to load. Alternatively, could send an http request with every select value change, but that may be too slow between UI submissions.
// Outputs SQL Views as [DBContainer, children: [Schema, children: [Category, children: [table, children: [column]]]]], where Category is MSSQL Extended Property 'View_Category'.
// Developed for SQL Server. Reads the following Extended Properties: MS_Description, View_Category, and View_DisplayName to add more information to the SQL View.
// Excluding DB Tables from the output so that the DB Manager can control what and how basic users view and extract data. Still giving the user read permissions to DB Tables so advanced users can leverage them in the text-console form.

header("Content-Type: application/json; charset=UTF-8");

//For development, connection.php is in the same folder, but this should be moved for security in production.
include_once "connection.php";

//Initialize and then build an object to JSONify
$arrayContainer = array();

//All of the DB Containers, to control what can be seen to certain containers.
$databaseCollection = ["NMML_AEP_SSL", "NMML_AEP_NFS"];

//Get schemas => categories => views => columns => top values
//Only get SQL Views to keep the selection simpler. User still has read access to all tables for cascading permissions and using the text editor option.
//SQL Server 2014 does not recognize "FOR JSON" queries
foreach ($databaseCollection as $database) {
    $objectContainer = new stdClass();
    $objectContainer->name = $database;
    $objectContainer->display = $database;
    $objectContainer->description = "";
    $arraySchema = array();
    $conn = connect($database);
    $schemas = odbc_exec($conn, "SELECT DISTINCT schemas.name, schemas.schema_id FROM sys.schemas INNER JOIN sys.views ON schemas.schema_id = views.schema_id ORDER BY schemas.name");
    while (odbc_fetch_row($schemas)) {
        $schemaId = odbc_result($schemas, "schema_id");
        $objectSchema = new stdClass();
        $objectSchema->name = odbc_result($schemas, "name");
        $objectSchema->display = odbc_result($schemas, "name");
        $objectSchema->description ="";
        $arrayCategory = array();
        // separate views by the extended_property View_Category.
        $categories = odbc_exec($conn, 
            "SELECT DISTINCT extended_properties.value
            FROM sys.views 
            LEFT JOIN sys.extended_properties 
                ON views.object_id = extended_properties.major_id 
                AND extended_properties.minor_id = 0 
                AND extended_properties.name = 'View_Category'
            WHERE schema_id = $schemaId
            ORDER BY extended_properties.value");
        while (odbc_fetch_row($categories)) {
            $categoryName = odbc_result($categories, "value");
            $objectCategory = new stdClass();
            $objectCategory->name = odbc_result($categories, "value");
            $objectCategory->display = odbc_result($categories, "value");
            $objectCategory->description = "";
            $arrayTable = array();
            $tables = @odbc_exec($conn,
               "SELECT
                    object_id
                ,   name = COALESCE(DisplayName.value, views.name)
                ,   realname = views.name
                ,	description = Description.value
                FROM sys.views
                LEFT JOIN sys.extended_properties AS DisplayName
                ON views.object_id = DisplayName.major_id
                    AND DisplayName.name = 'View_DisplayName'
                    AND DisplayName.minor_id = 0
                LEFT JOIN sys.extended_properties AS Category
                ON views.object_id = Category.major_id
                    AND Category.name = 'View_Category'
                    AND Category.minor_id = 0
                LEFT JOIN sys.extended_properties AS Description
                ON views.object_id = Description.major_id
                    AND Description.name = 'MS_Description'
                    AND Description.minor_id = 0
                WHERE schema_id = $schemaId 
                    AND COALESCE(Category.value, '') = '$categoryName'
                ORDER BY COALESCE(DisplayName.value, views.name)");
            while (@odbc_fetch_row($tables)) {
                $objectId = odbc_result($tables, "object_id");
                $objectTable = new stdClass();
                $objectTable->name = odbc_result($tables, "realname");
                $objectTable->display = odbc_result($tables, "name");
                //sys.extended_properties.value returns a value that is not UTF-8.
                $objectTable->description = utf8_encode(odbc_result($tables, "description"));
                $arrayColumn = array();
                $columns = odbc_exec($conn, 
                     "SELECT
                        name = COALESCE(DisplayName.value, columns.name)
                    ,	realname = columns.name
                    ,	description = Description.value
                    ,   datatype = types.name
                    FROM sys.columns
                    LEFT JOIN sys.types
                    ON columns.user_type_id = types.user_type_id
                    LEFT JOIN sys.extended_properties AS DisplayName
                    ON columns.object_id = DisplayName.major_id
                        AND DisplayName.name = 'View_DisplayName'
                        AND DisplayName.minor_id = columns.column_id
                    LEFT JOIN sys.extended_properties AS Description
                    ON columns.object_id = Description.major_id
                        AND Description.name = 'MS_Description'
                        AND Description.minor_id = columns.column_id 
                    WHERE columns.object_id = $objectId
                    ORDER BY columns.column_id");
                while (odbc_fetch_row($columns)) {
                    $objectColumn = new stdClass();
                    $objectColumn->name = odbc_result($columns, "realname");
                    $objectColumn->display = odbc_result($columns, "name");
                    $objectColumn->description = utf8_encode(odbc_result($columns, "description"));
                    array_push($arrayColumn, $objectColumn);
                }
                $objectTable->children = $arrayColumn;
                array_push($arrayTable, $objectTable);
            }
            $objectCategory->children = $arrayTable;
            array_push($arrayCategory, $objectCategory);
        }
        $objectSchema->children = $arrayCategory;
        array_push($arraySchema, $objectSchema);
    }
    $objectContainer->children = $arraySchema;
    array_push($arrayContainer, $objectContainer);
}

$result = new stdClass();
$result->children = $arrayContainer;
echo json_encode($result);


?>