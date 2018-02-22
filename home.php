<!DOCTYPE html>
<html lang="en-US">
	<head>
		<title>Alaska Ecosystem Database Reporting Service</title>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<meta name="Description" content="Easy access to the AEP database.">
		<link rel="icon" type="image/x-icon" href="./icons/main.png">
        
        <!-- AngularJS -->
        <script src="https://ajax.googleapis.com/ajax/libs/angularjs/1.6.4/angular.min.js"></script>
        
        <!-- Google Charts -->
        <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>

        <!-- My stylesheets -->
        <link rel="stylesheet" type="text/css" href="styles/stylesheet1.css">

        <!-- JS leveraging AngularJS -->
        <script src="js/main.js"></script>
        
        <!-- JS outside of AngularJS -->
        <script>
            // Some selects start as gray for the 'placeholder'.
            function ungray(el) {
                el.style.color = "black";
            }
        </script>
        
        <!-- Template for Google Chart divs. -->
        <template id="template-chart">
            <!-- Have to assign event listeners in JS -->
            <div class="chart-container-parent">
                <div class="div-scrollable">
                    <div class="chart-container"></div>
                </div>                
                <p class="chart-instructions"></p>
                <button class="chart-close" title="close">X</button>
                <div class="div-zoom">
                    <button class="chart-zoom-in" title="larger">+</button>
                    <button class="chart-zoom-out" title="smaller">-</button>
                </div>
            </div>
        </template>
	</head>
    <body ng-app="queryBuilderApp">
        <!-- Selection Form -->
        <div id="query-builder" ng-controller="queryBuilderController">
            <div id="splash-screen" ng-if="isLoading">
                <div id="animated-loader"></div>
                <span>Loading...</span>
            </div>
            <div ng-if="!data.useConsole" ng-repeat="(indexGroup, group) in data.collectionTable as collectionTable">
                <p class="divider" ng-if="collectionTable.length > 1">
                    <span>{{group.table.display || 'Select a table'}}</span>
                    <span class="divider"></span>
                    <span>
                        <button ng-if="collectionTable.length > 1" 
                                ng-click="group.hide = !group.hide"
                                ng-class="group.hide ? 'button-showgroup' : 'button-hidegroup'"
                                title="collapse/expand">
                            &#9658;
                        </button>
                        <button class="button-removetable" 
                                ng-if="collectionTable.length > 1" 
                                ng-click="collectionTable.splice(indexGroup, 1)"
                                title="remove this table">
                            &#10060;
                        </button>
                    </span>
                </p>
                <div ng-if="!group.hide || collectionTable.length == 1">
                    <fieldset ng-class="{ 'region-table': true, table: true, indented: collectionTable.length > 1 }">
                        <legend>Find the Table</legend>
                        <div class="help">
                            <span class="tooltip">
                                To select a table, first select the database, project, and then category containing that table.
                            </span>
                        </div>
                        <div class="row">
                            <label class="cell">Database</label>
                            <!-- Cascading selects do not work with <option ng-repeat="value in queryBuilder.values" value="{{value}}">{{value.display}}</option> -->
                            <select class="cell" ng-model="group.container" ng-options="item as item.display for item in queryBuilder.children track by item.name"></select>
                        </div>
                        <div class="row">
                            <label class="cell">Project</label>
                            <select  class="cell" ng-model="group.schema" ng-options="item as item.display for item in group.container.children track by item.name"></select>
                        </div>
                        <div class="row">
                            <label class="cell">Category</label>
                            <select class="cell" ng-model="group.category" ng-options="item as item.display for item in group.schema.children track by item.name"></select>
                        </div>
                        <div class="row">
                            <label class="cell">Table</label>
                            <select class="cell" ng-model="group.table" ng-change="updateTable(group)" ng-options="item as item.display for item in group.category.children track by item.name"></select>
                        </div>
                        <div ng-if="group.table.description" style="max-width: 350px">
                            <small>Description: {{group.table.description}}</small>
                        </div>
                    </fieldset>
                    <fieldset ng-class="{ 'region-columns': true, table: true, indented: collectionTable.length > 1 }">
                        <legend>Mark Columns to Display</legend>
                        <div class="help">
                            <span class="tooltip">
                                Only the marked columns and custom columns are included in the final results. Click <i>add</i> to create your own custom column. If your custom column requires an aggregate function (calculates across multiple rows), select it from the first drop-down. In the second input for a custom column, select a column name or type your own equation. You should surround table names with square brakets, and text values with single-quotes (eg. <i>'This was ' + [AnimalName]</i>). Click <i>x</i> to remove that custom column.
                            </span>
                        </div>
                        <span class="row" ng-hide="group.table">
                            <small><i>Select a table above to view its columns here.</i></small>
                        </span>     
                        <div ng-if="group.table">
                            <label><input type="checkbox" ng-model="group.checkAll" ng-click="toggleAll(group)"><em>Check or uncheck all</em></label>
                            <!-- Column names displayed in 3 columns, reading up-to-down and left-to-right -->
                            <div class="row">
                                <div>
                                    <label class="row" ng-repeat="(indexColumn, column) in group.table.children | columnFilter:0:false" title="{{column.description}}">
                                        <input type="checkbox" ng-model="column.selected" ng-change="optionToggle(group)">
                                        {{column.display}}
                                    </label>
                                </div>
                                <div class="cell">
                                    <label class="row" ng-repeat="(indexColumn, column) in group.table.children | columnFilter:1:false" title="{{column.description}}">
                                        <input type="checkbox" ng-model="column.selected" ng-change="optionToggle(group)">
                                        {{column.display}}
                                    </label>
                                </div>
                                <div class="cell">
                                    <label class="row" ng-repeat="(indexColumn, column) in group.table.children | columnFilter:2:false" title="{{column.description}}">
                                        <input type="checkbox" ng-model="column.selected" ng-change="optionToggle(group)">
                                        {{column.display}}
                                    </label>
                                </div>
                            </div>
                            <label>Custom Columns:</label>
                            <datalist id="{{'columnList' + indexGroup}}">
                                <option ng-repeat="item in group.table.children" ng-value="'[' + item.name + ']'"></option>
                            </datalist>
                            <div class="row" ng-repeat="(index, column) in group.table.children | columnFilter:0:true">
                                <label class="cell">{{column.number}}</label>
                                <select class="cell gray-select" ng-model="column.aggregate" onchange="ungray(this)">
                                    <option value="" hidden disabled selected>agg function</option>
                                    <option value=" "></option>
                                    <option value="AVG">Average</option>
                                    <option value="COUNT">Row Count</option>
                                    <option value="MIN">Min</option>
                                    <option value="MAX">Max</option>
                                    <option value="SUM">Sum</option>
                                    <option value="STDEV">Std Dev</option>
                                    <option value="STDEVP">Pop Std Dev</option>
                                    <option value="VAR">Variance</option>
                                    <option value="VARP">Pop Var</option>
                                </select>
                                <input class="cell" ng-model="column.column" type="text" list="{{'columnList' + indexGroup}}" placeholder="column or equation...">
                                <button class="cell" ng-click="removeColumn(group.table.children, index)">x</button>
                            </div>
                            <button class="row" ng-click="group.table.children.push({ customColumn: true, aggregate: '', column: '', number: settings.columnCounter, display: 'custom column ' + settings.columnCounter, name: 'CustomColumn' + settings.columnCounter }); settings.columnCounter = settings.columnCounter + 1;">Add</button>
                            <!-- Note that settings.columnCounter++ does not work. -->
                        </div>
                    </fieldset>
                    <fieldset ng-class="{ 'region-filters': true, table: true, indented: collectionTable.length > 1 }">
                        <legend>Create Filters</legend>
                         <div class="help">
                            <span class="tooltip">
                                Filter what rows to include from this table. Click <i>add</i> to create a new filter. Click <i>x</i> to remove that filter.
                                <ul>
                                    <li>Change the 1st drop-down to <i>or</i> to include rows where either the previous or the current filter are true.</li>
                                    <li>Choose a table column from the 2nd drop-down.</li>
                                    <li>Choose an evaluator from the 3rd drop-down: = (equals), &ne; (not equals), <i>contains</i> (contains the text anywhere), <i>not contains</i>, &le; (less than or equals), &ge; (greater than or equals), <i>between</i> (inclusively between the 2 values), or <i>not between</i>.</li>
                                    <li>Lastly, enter the values separated by commas (eg. column = <i>1, 2</i> will return rows where column is <i>1</i> and rows where it is <i>2</i>). If a value contains a comma, escape it with a back-slash (<i>\,</i>).</li>
                                    <li>Advanced users can use a SQL expression for a filter value- just surround it with double-quotes (eg. <i>"[AnimalName] + 'A'"</i>) and escape the commas.</li>
                                </ul>
                            </span>
                        </div>
                        <span ng-hide="group.table">
                            <small><i>Select a table above to view its filters here.</i></small>
                        </span>
                        <div class="table" ng-if="group.table">
                            <div class="row" ng-repeat="(index, item) in group.filters as filters">
                                <select class="cell" ng-model="item.condition">
                                    <option value="AND">and</option>
                                    <option value="OR">or</option>
                                </select>
                                <select class="cell gray-select" onchange="ungray(this)" ng-model="item.column" ng-options="value as value.display for value in group.table.children track by value.name">
                                    <option value="" disabled hidden>column...</option>
                                </select>
                                <select class="cell" ng-model="item.evaluator">
                                    <option value="=">=</option>  
                                    <option value="!=">&ne;</option>
                                    <option value="LIKE">contains</option>
                                    <option value="NOT LIKE">not contains</option>
                                    <option value="<=">&le;</option>
                                    <option value=">=">&ge;</option>
                                    <option value="BETWEEN">between</option>
                                    <option value="NOT BETWEEN">not between</option>
                                </select>
                                <input class="cell" type="text" ng-model="item.value" placeholder="value1, value2, ...">
                                <button class="cell" ng-click="filters.splice(index,1)">x</button>
                            </div>
                            <button class="row" ng-click="group.filters ? group.filters.push({ condition: 'AND', evaluator: '='}) : group.filters = [{ condition: 'AND', evaluator: '=', column: '', value: '' }]">Add</button>
                        </div>
                    </fieldset>
                    <fieldset ng-if="indexGroup > 0" ng-class="{ 'region-joins': true, table: true, indented: collectionTable.length > 1 }">
                        <legend>Match Table Columns</legend>
                        <small ng-if="!group.table"><i>Select a table above before joining with another table</i></small>
                        <div ng-if="group.table">
                            <small class="row" ng-if="!group.joinConditions || group.joinConditions.length == 0">Warning: there must be at least one match.</small>
                            <div class="row" ng-repeat="(index, join) in group.joinConditions as joinConditions">
                                <select class="cell" ng-model="join.condition">
                                    <option value="AND">and</option>
                                    <option value="OR">or</option>
                                </select>
                                <select class="cell gray-select" ng-model="join.rightColumn" ng-options="column as column.display for column in group.table.children track by column.name" onchange="ungray(this)">
                                    <option value="" disabled hidden>column...</option>
                                </select>
                                <select class="cell" ng-model="join.evaluator">
                                    <option value="=">=</option>
                                    <option value="&ne;">&ne;</option>
                                    <option value="&lt;">&lt;</option>
                                    <option value="&le;">&le;</option>
                                    <option value="&ge;">&ge;</option>
                                    <option value="&gt;">&gt;</option>
                                </select>
                                <select class="cell" ng-if="indexGroup > 1" ng-model="join.leftTable" ng-options="table as table.table.displayAlias for table in (collectionTable | limitTo:indexGroup) track by table.table.alias ">
                                </select>
                                <select class="cell gray-select" onchange="ungray(this)" ng-model="join.leftColumn" ng-options="column as column.display for column in join.leftTable.table.children track by column.name">
                                    <option value="" disabled hidden>other table column...</option>
                                </select>
                                <button class="cell" ng-click="joinConditions.splice(index, 1)">x</button>
                            </div>
                            <button class="row" ng-click="group.joinConditions ? group.joinConditions.push({ condition: 'AND', evaluator: '=', leftTable: collectionTable[indexGroup - 1]}) : group.joinConditions = [{ condition: 'AND', evaluator: '=', leftTable: collectionTable[indexGroup - 1]}]">Add match</button>
                        </div>
                    </fieldset>
                </div>
            </div>
            <fieldset id="region-advanced">
                <legend>Advanced Options</legend>
                <div class="help">
                    <span class="tooltip">
                        <ul>
                            <li>Check <i>Output distinct rows only</i> to keep all rows in the output unique. This is especially useful when using aggregate functions in custom columns.</li>
                            <li>Click <i>Join another table</i> to join the rows from any number of tables. The criteria to match rows are automatically generated when the <i>auto-match join columns</i> is checked.</li>
                            <li>Click <i>Write my own query</i> to write a query in a text console.</li>
                        </ul>
                    </span>
                </div>
                <div ng-if="collectionTable[0].table && !data.useConsole">
                    <label class="row">
                        <input type="checkbox" ng-model="data.distinctRows">
                        Output distinct rows only
                    </label>
                    <hr>
                    <div class="table">
                        <div class="row">
                            <button class="cell" ng-click="collectionTable.push({ })">Join another table</button>
                            <label class="cell"><input type="checkbox" ng-model="settings.autoMatch">Auto-match join columns</label>
                        </div>
                        <div class="row" ng-if="collectionTable.length > 1">
                            <label class="cell">Output joins</label>
                            <div class="cell">
                                <label><input type="radio" ng-model="data.innerJoin" ng-value="true">Matched rows only</label>
                                <label class="row"><input type="radio" ng-model="data.innerJoin" ng-value="false">All rows</label>
                            </div>
                        </div>
                    </div>
                    <hr>
                </div>
                <button class="row" ng-click="data.useConsole = !data.useConsole" ng-bind="data.useConsole ? 'Use query builder' : 'Write my own query'"></button>
            </fieldset>
            <fieldset id="region-console" ng-if="data.useConsole">
                <legend>Write A Query</legend>
                <div class="row">
                    <label class="cell">Database</label>
                    <select class="cell" ng-model="data.consoleDatabase" ng-options="item as item.display for item in queryBuilder.children track by item.name"></select>
                </div>
                <textarea ng-model="data.consoleQuery"></textarea>
            </fieldset>
            <fieldset id="region-output">
                <legend>Output Results</legend>
                <div class="row">
                    <div class="cell">
                        <button type="submit" ng-click="getPreview()" ng-disabled="!collectionTable[0].table && !data.useConsole">View</button>
                    </div>
                    <div class="cell"></div>
                </div>
                <div class="row">
                    <div class="cell">
                        <button type="submit" ng-click="getFile()" ng-disabled="!collectionTable[0].table && !data.useConsole">Save As</button>
                    </div>
                    <div class="cell">
                        <select ng-model="data.fileType">
                            <option value="xlsx">Excel</option>
                            <option value="txt">Tab-seperated (.txt)</option>
                            <option value="r">R Script</option>
                        </select>
                    </div>
                   
                </div>
            </fieldset>
            <br>
            <a href="#" target="_blank">Open a new window</a>
        </div>
        <!-- Tables and Graphs -->
        <div id="display-results" ng-controller="displayController" ng-show="data">
            <p class="row warning" ng-if="truncated">Warning: the Query Builder returned 1,000 or more rows. Only the first 1,000 rows are shown and used for graphs and charts below.</p>
            <button ng-click="initChart(chartType)">Create</button>
            <select ng-model="chartType">
                <option>Area</option>
                <option>Area(stacked)</option>
                <option>Bar</option>
                <option>Bar(stacked)</option>
                <option>Bubble</option>
                <option>Calendar</option>
                <option>Candlestick</option>
                <option>Column</option>
                <option>Combo</option>
                <option>Geo</option>
                <option>Histogram</option>
                <option>Line</option>
                <option>Org</option>
                <option>Pie</option>
                <option>Sankey</option>
                <option>Scatter</option>
                <option>Stepped Area</option>
                <option>Timeline</option>
                <option>Treemap</option>
            </select>
            <div class="help-inline">
                <span class="tooltip">
                    Select the chart or graph type from the drop-down, and then click <i>create</i>. A new, blank object is created above the results table. Read the instructions overlaying the object to determine which column to drag over from the results table. The column order is important. Hover over the object to see its increase size (<i>+</i>), decrease size (<i>-</i>), and close (<i>x</i>) buttons. You can create as many charts and graphs as you like. Click on a data point in an object or in the table to highlight all matching data points in the other objects.
                </span>
            </div>
            <div id="div-charts"></div>
            <div id="table_div"></div>
        </div>
    </body>
</html>