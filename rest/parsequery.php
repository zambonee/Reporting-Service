<?php

// Convert user selection to a SQL query.
// JSON object is {table, [columns + customcolumns], [filters], [joinconditions]}
// Query format is 
//      "SELECT {DISTINCT} [columns + customcolumns] FROM (SELECT * {, [customcolumns]} FROM table WHERE [filters]) AS A {INNER|OUTER} JOIN ... ON [matches]
// The query format is awful, but there has not been any significant performance hits in testing. It is designed to simplify conversion from user selection to text, giving the user as much control as possible without lossing usability
// Returns ["database", "query"]

function parsequery($data, $top = false, $n = 100) {
    $database = "";
    $query = "";
    // database and query will be different $post properties depending on whether the user submitted their query from a text-console.
    if (isset($data->useConsole) && $data->useConsole) {
        $query = $data->consoleQuery;
        $database = $data->consoleDatabase;
    } 
    // Make the query based on user input.
    else {
        $select = $top ? "SELECT TOP $n" : "SELECT";
        $collectionColumn = array(); // For the query. key = alias.
        $collectionLookupColumn = array(); // Lookup column-table for dups
        $collectionTable = array();
        foreach ($data->collectionTable as $group) {
            $schema = $group->schema->name;
            $tableName = $group->table->name;
            $tableAlias = $group->table->alias;
            $tableDisplay = $group->table->displayAlias;
            $database = $group->container->name;
            $columnList = array("*"); //Select all columns in the subquery, appending user-defined columns
            $groupByColumns = array();
            foreach ($group->table->children as $column) {
                if (isset($column->selected) && $column->selected) {
                    $columnName = $column->name;
                    $display = $column->display;
                    array_push($groupByColumns, "[$columnName]");
                    if (isset($collectionLookupColumn[$display])) {
                        $keys = array_keys($collectionColumn);
                        $keys[array_search($display, $keys)] = $display . "(" . $collectionLookupColumn[$display] . ")";
                        $collectionColumn = array_combine($keys, $collectionColumn);
                        $display = "$display($tableDisplay)";
                    }
                    else {
                        $collectionLookupColumn[$display] = $tableDisplay;
                    }
                    $collectionColumn[$display] = "[$tableAlias].[$columnName]";
                }
            }
            // Loop through again for the custom columns so PARTITION BY selected columns can be appended. There cannot be too many columns to significantly slow down this process.
            // Use PARTITION BY instead of GROUP BY to simplify query building and to keep the user from having to write out the entire db.schema.table.column in custom columns.
            $columns = implode(", ", $groupByColumns);
            $groupBy = "";
            if (!empty($columns)) {
                $groupBy = "OVER (PARTITION BY $columns)";
            }
            foreach ($group->table->children as $column) {
                if (isset($column->customColumn)) {
                    $agg = trim($column->aggregate);
                    $value = $column->column;
                    $alias = $column->display;
                    if (empty($value)) {
                        $value = "NULL";
                    }
                    if (!empty($agg)) {
                        if ($agg == "COUNT" && strtoupper($value) == "NULL") {
                            $value = "COUNT(*) $groupBy";
                        }
                        else {
                            $value = "$agg($value) $groupBy";
                        }
                    }
                    $columnList[$alias] = "[$alias] = $value";
                    $collectionColumn[$alias] = "[$tableAlias].[$alias]";
                }
            }
            $filters = "";
            $havings = "";
            if (isset($group->filters)) {
                foreach ($group->filters as $object) {
                    $result = "";
                    $column = "";
                    // Column name or "custom column" equation.
                    if (isset($object->column->customColumn)) {
                        $agg = $object->column->aggregate;
                        $function = $object->column->column;
                        if (!empty($agg)) {
                            $function = "$agg($function)";
                        }
                        $column = $function;
                    }
                    else {
                        $column = "[" . $object->column->name . "]";
                    }
                    $evaluator = $object->evaluator;
                    // Split value by commas that are not escaped with a back-slash.
                    $collectionValue = preg_split("/(?<!\\\),/", $object->value);
                    $joinStatement = ($evaluator == "=" || $evaluator == "LIKE") ? " OR" : " AND";
                    for ($k = 0; $k < count($collectionValue); $k++) {
                        // values wrapped in double-quotes should be taken literally and should not be wrapped in quotes.
                        $isLiteral = false;
                        $value = $collectionValue[$k];
                        if (preg_match("/^\s*\"\s*(.*)\s*\"\s*$/", $value, $match)) {
                            $value = $match[1]; //match[0] is the whole string.
                            $isLiteral = true;
                        }
                        else {
                            $value = str_replace("'", "''", trim($collectionValue[$k]));
                        }
                        $quote = $isLiteral ? "" : "'";
                        $valueResult = "";
                        // Account for NULLs. Empty values are considered NULL. If a user wants to search for empty strings, they should be more advanced users anyways.
                        // Allow user to filter for empty string if they know what they're doing with value = "". Without double-quotes, assume the user does not know what they are doing with NULL.
                        if ((empty($value) && !$isLiteral) || strtoupper($value) == "NULL") {
                            if ($evaluator == "=" || $evaluator == "LIKE") {
                                $valueResult = "$column IS NULL";
                            }
                            else if ($evaluator == "!=" || $evaluator == "NOT LIKE") {
                                $valueResult = "$column IS NOT NULL";
                            }
                        }
                        else {
                            if ($evaluator == "LIKE" || $evaluator == "NOT LIKE") {
                                // Most users will not need to use wildcards. So, escape wildcards and append with %. Advanced users will use double-quotes.
                                if (!$isLiteral) {
                                    $value = preg_replace("/[%_[]/", "[$0]", $value);
                                    $value = "%$value%";
                                }
                            }
                            if ($evaluator == "BETWEEN" || $evaluator == "NOT BETWEEN") {
                                if ($k == 0) {
                                    $valueResult = "$column $evaluator $quote$value$quote";
                                }
                                else {
                                    $valueResult = "$quote$value$quote";
                                }
                            }
                            else {
                                $valueResult = "$column $evaluator $quote$value$quote";
                            }
                        }
                        if ($k == 0) {
                            $result = "$valueResult";
                        }
                        else {
                            $result .= "$joinStatement $valueResult";
                        }
                    }
                    if (isset($object->column->aggregate) && !empty($object->column->aggregate)) {
                        $condition = empty($havings) ? "\r\n\tWHERE" : "\r\n\t\t" . $object->condition;
                        $result = " $condition ($result)";
                        $havings .= $result;
                    }
                    else {
                        $condition = empty($filters) ? "\r\n\tWHERE" : "\r\n\t\t" . $object->condition;
                        $result = " $condition ($result)";
                        $filters .= $result;
                    }
                }
            }
            $matches = "";
            if (isset($group->joinConditions)) {
                foreach ($group->joinConditions as $i => $match) {
                    $condition = $i == 0 ? "ON" : "\t" . $match->condition;
                    $rightColumn = $match->rightColumn->name;
                    $evaluator = $match->evaluator;
                    $leftTable = $match->leftTable->table->alias;
                    $leftColumn = $match->leftColumn->name;
                    $matches .= "\r\n$condition [$tableAlias].[$rightColumn] $evaluator [$leftTable].[$leftColumn]";
                }   
            }
            $column = implode("\r\n\t\t,", $columnList);
            $query = "(\r\n\tSELECT $column \r\n\tFROM [$database].[$schema].[$tableName] $filters $havings \r\n) AS $tableAlias $matches";
            array_push($collectionTable, $query);
        }
        $join = isset($data->innerJoin) && $data->innerJoin ? " INNER JOIN " : " FULL JOIN ";
        $columns = implode("\r\n\t,", array_map(function($value, $key){ return "[$key] = $value"; }, $collectionColumn, array_keys($collectionColumn)));
        $tables = implode($join, $collectionTable);
        $distinct = isset($data->distinctRows) && $data->distinctRows ? "DISTINCT" : "";
        $query = "$select $distinct \r\n\t$columns \r\nFROM $tables";
        $database = $data->collectionTable[0]->container->name;
    }
    return array("database" => $database, "query" => $query);
}

?>
