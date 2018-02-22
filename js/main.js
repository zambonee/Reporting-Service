// All AngularJS
var app = angular.module("queryBuilderApp", []);
// Split into 2 ngControllers- one for the query builder and one for the results output in the browser.
// Query Builder
app.controller("queryBuilderController", function($scope, $http, $rootScope) {
    // ng-if="isLoading" to show/hide the splash screen.
    $scope.isLoading = true; 
    $http.get("rest/getquerybuilder.php")
    .then(
        function(response) {
            $scope.queryBuilder = response.data;
            // Stop the splash screen.
            $scope.isLoading = false; 
        }
        , function(error) {
            alert("Error: could not connect to the server.");
            // Stop the splash screen.
            $scope.isLoading = false;
        }
    );
    // Set all data to be sent to the server in $scope.data.
    // Need some default values for ng-models: innerJoin, fileType, and collectionTable.
    $scope.data = { 
        // If joining tables, use INNER or FULL?
        innerJoin: true, 
        // If saving output, what format?
        fileType: "xlsx", 
        // All joined table information, including SELECT columns, custom columns, and WHERE clauses. Each table needs a checkAll property to tell the web app whether to select all columns automatically.
        collectionTable: Array({ checkAll: true })
    };
    // ng-model on checkbox does not work on a primitive. Instead, bind to an object property.
    $scope.settings = { 
        // Track custom columns created by the user. Do not track by array size because it can get confusing when the user deletes custom columns and the indexes shift.
        autoMatch: true, 
         // Automatically create JOIN ON statements when a new table is added to the query builder.
        columnCounter: 1 
    };
    $scope.updateTable = function(group) {
        // Set table aliases here in case user selects multiples of the same table. 
        for (var i = 0; i < $scope.data.collectionTable.length; i++) {
            var matches = $scope.data.collectionTable.filter(function(item) { 
                return item.table.name == this.table.name;
            }, $scope.data.collectionTable[i]);
            if (matches.length > 1) {
                for (var k = 0; k < matches.length; k++) {
                    matches[k].table.alias = matches[k].table.name + (k + 1);
                    matches[k].table.displayAlias = matches[k].table.display + " (" + (k + 1) + ")";
                }
            }
            else {
                matches[0].table.alias = matches[0].table.name;
                matches[0].table.displayAlias = matches[0].table.display;
            }
        }
        // Find potential join on columns after the user selects another table to join.
        if ($scope.settings.autoMatch) {
            group.joinConditions = Array();
            for (var groupIndex = 0; groupIndex < $scope.data.collectionTable.indexOf(group); groupIndex++) {
                var otherGroup = $scope.data.collectionTable[groupIndex];
                // Make sure they are not the same SQL view- otherwise every column will match.
                if (group.schema.name != otherGroup.schema.name || group.table.name != otherGroup.table.name) {
                    for (var columnIndex = 0; columnIndex < group.table.children.length; columnIndex++) {
                        for (var otherIndex = 0; otherIndex < otherGroup.table.children.length; otherIndex++) {
                            var column = group.table.children[columnIndex];
                            var otherColumn = otherGroup.table.children[otherIndex];
                            if (column.name == otherColumn.name && column.datatype == otherColumn.datatype) {
                                group.joinConditions.push({ 
                                    condition: "AND", 
                                    rightColumn: column, 
                                    evaluator: "=",
                                    leftTable: otherGroup,
                                    leftColumn: otherColumn
                                });
                            }
                        }
                    }
                }
            }
        }
        // Update column checkboxes to match the "check or uncheck all"
        $scope.toggleAll(group);
    }
    // Check all options in the DB column list. Do this when the CheckAll is changed or when the column list is set (to account for a user click when there are no columns displayed).
    $scope.toggleAll = function(group) {
        if (group && group.table) {
            angular.forEach(group.table.children, function(arg) { arg.selected = group.checkAll});
        }
    }
    // Update the CheckAll input.
    $scope.optionToggle = function(group) {
        group.checkAll = group.table.children.every(function(arg) { return arg.customColumn == true ? true : arg.selected; });
    }
    // Cannot access $scope.columnCounter from inline ng-click.
    $scope.addColumn = function(columns) {
        columns.push({ 
            customColumn: true, 
            aggregate: '', 
            column: '', 
            number: $scope.settings.columnCounter, 
            display: 'custom column ' + $scope.settings.columnCounter, 
            name: 'CustomColumn' + $scope.settings.columnCounter 
        }); 
        $scope.settings.columnCounter++;
    }
    // Cannot get the column index because the ng-repeat is filtered, so the index is based on a different array.
    $scope.removeColumn = function(collection, index) {
        var counter = 0;
        for (var i = 0; i < collection.length; i++) {
            if (collection[i].customColumn == true) {
                if (counter == index) {
                    collection.splice(i, 1);
                    return;
                }
                counter++;
            }
        }
    }
    // $scope.data has a lot of data to make the query selectors work smoothly that do not need to be sent to server.
    $scope.cleanData = function() {
        var data = JSON.parse(JSON.stringify($scope.data)); // deep clone
        if (data.useConsole) {
            data = { 
                useConsole: true, 
                consoleDatabase: data.consoleDatabase.name, 
                consoleQuery: data.consoleQuery, 
                fileType: data.fileType 
            };
        }
        else {
            for (var i = 0; i < data.collectionTable.length; i++) {
                var table = data.collectionTable[i];
                table.category = { name: table.category.name };
                table.container = { name: table.container.name, display: table.container.display };
                table.schema = { name: table.schema.name, display: table.schema.name };
            }
        }
        return data;
    }
    // Get JSON for the Google API
    $scope.getPreview = function() {
        var data = JSON.stringify($scope.cleanData());
        $scope.isLoading = true;
        $http.post("rest/getquerypreview.php", data)
        .then(
            function(response) {
                $rootScope.$emit("dataPreview", response.data);
                $scope.isLoading = false;
            }, 
            function(error) {
                alert(error.data);
                $scope.isLoading = false;
            }
        );
    }
    // Save a file
    $scope.getFile = function() {
        var data = JSON.stringify($scope.cleanData());
        $http.post("rest/gettable.php", data, { responseType: "arraybuffer" })
        .then(
            function(response) {
                var blob = new Blob([response.data], { type: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" });
                var objectUrl = URL.createObjectURL(blob);
                var a = document.createElement("a");
                a.href = objectUrl;
                a.target = "_blank";
                if ($scope.data.fileType == "xlsx") {
                    a.download = "queryresults.xlsx";
                }
                else if ($scope.data.fileType == "r") {
                    a.download = "queryresult.r";
                }
                else {                                
                    a.download = "queryresult.txt";
                }
                a.click();
            },
            function(error) {
                alert(error.data);
            }
        );
    }
});
// Browser Output
app.controller("displayController", function($scope, $rootScope) {
    // Load Google API. No significant performance hit by loading all of the packages.
    google.charts.load('current', {
        packages: [ "table", "corechart", "calendar", "geochart", "orgchart", "sankey", "timeline", "treemap" ],
        mapsApiKey: "AIzaSyBVer_t22bZrw_wOd0vZpcbBn2UUpZlTks"
    });
    // The Google DataTable
    $scope.data = null;
    // Chart type to keep track of when the user hits the CREATE button.
    $scope.chartType = "Line";
    // Cannot access Charts by container elements, so maintain an array of charts with their relevant information { chart, type, columns, options}, where chart is the Chart object, type is string of chart type, columns is an array of DataView columns, and options is chart option object.
    $scope.charts = new Array();
    // table as a scope-global variable to reference from select listeners.
    $scope.table;
    // Listen for changes to dataPreview set from the other controller.
    $rootScope.$on("dataPreview", function(event, args) {
        $scope.data = new google.visualization.DataTable(args.result);
        // truncated = true when rows were truncated for performance.
        $scope.truncated = args.truncated;
        // Google Table
        $scope.table = new google.visualization.Table(document.getElementById('table_div'));
        $scope.table.draw($scope.data, {showRowNumber: true, page: 'enabled', pageSize: 100 });
        // Can access the headers pretty easily because they are the only TH elements in the table object.
        var headers = document.getElementById('table_div').getElementsByTagName('th');
        for (var i = 1; i < headers.length; i++) {
            var h = headers[i];
            h.draggable = true;
            h.addEventListener("dragstart", $scope.drag); // Do NOT use "drag" event- dataTransfer only works in "dragstart".
        }
    });
    // Create the chart from scratch.
    $scope.initChart = function(type) {
        var div = document.getElementById("template-chart").content.cloneNode(true);
        // Cannot getElementsByClassName from the clone, so append it and then reference the newly-created element.
        document.getElementById("div-charts").appendChild(div);
        var children = document.getElementById("div-charts").children;
        div = children[children.length - 1];
        var divChart = div.getElementsByClassName("chart-container")[0];
        var chart;
        var options = { };
        // Although it would be easier to read, cannot use google.visualization.ChartWrapper because that needs a div ID.
        switch (type) {
            case "Area":
                chart = new google.visualization.AreaChart(divChart);
                break;
            case "Area(stacked)":
                chart = new google.visualization.AreaChart(divChart);
                options.isStacked = true;
                break;
            case "Bar":
                chart = new google.visualization.BarChart(divChart);
                break;
            case "Bar(stacked)":
                chart = new google.visualization.BarChart(divChart);
                options.isStacked = true;
                break;
            case "Bubble":
                chart = new google.visualization.BubbleChart(divChart);
                break;
            case "Calendar":
                chart = new google.visualization.Calendar(divChart);
                // Unlike the other Charts, this does not draw outside of its starting bounding box. So, set a reasonable size.
                options.calendar = { cellSize: 10 };
                options.width = 600;
                options.height = 5000;
                break;
            case "Candlestick":
                chart = new google.visualization.CandlestickChart(divChart);
                break;
            case "Column":
                chart = new google.visualization.ColumnChart(divChart);
                break;
            case "Combo":
                chart = new google.visualization.ComboChart(divChart);
                break;
            case "Geo":
                chart = new google.visualization.GeoChart(divChart);
                break;
            case "Histogram":
                chart = new google.visualization.Histogram(divChart);
                break;
            case "Line":
                chart = new google.visualization.LineChart(divChart);
                break;
            case "Org":
                chart = new google.visualization.OrgChart(divChart);
                break;
            case "Pie":
                chart = new google.visualization.PieChart(divChart);
                break;
            case "Sankey":
                chart = new google.visualization.Sankey(divChart);
                break;
            case "Scatter":
                chart = new google.visualization.ScatterChart(divChart);
                break;
            case "Stepped Area":
                chart = new google.visualization.SteppedAreaChart(divChart);
                break;
            case "Timeline":
                chart = new google.visualization.Timeline(divChart);
                break;
            case "Treemap":
                chart = new google.visualization.TreeMap(divChart);
                break;
        }
        var item = { chart: chart, type: type, columns: [], options: options };
        $scope.charts.push(item);
        // Event listeners from the template have to be assigned in JS.
        div.addEventListener("dragover", $scope.allowDrop);
        div.addEventListener("drop", $scope.drop);
        // Button to remove this chart.
        div.getElementsByClassName("chart-close")[0]
            .addEventListener("click", function(event) { 
                var div = event.target.closest(".chart-container-parent");
                var siblings = Array.prototype.slice.call(div.parentElement.children);
                var index = siblings.indexOf(div);
                div.parentElement.removeChild(div);
                $scope.charts.splice(index, 1);
        });
        // Zoom buttons.
        div.getElementsByClassName("chart-zoom-in")[0].addEventListener("click", function() { $scope.resizeCharts(divChart, true); });
        div.getElementsByClassName("chart-zoom-out")[0].addEventListener("click", function() { $scope.resizeCharts(divChart, false); });
        // Interactivity between chart and table.
        google.visualization.events.addListener(chart, 'select', function() { 
            var selection = chart.getSelection();
            // Have to null the column attribute for selection to work.
            for (var i = 0; i < selection.length; i++) {
                selection[i].column = null;
            }
            $scope.table.setSelection(selection); 
        });
        google.visualization.events.addListener($scope.table, 'select', function() {
            for (var i = 0; i < $scope.charts.length; i++) {
                var chart = $scope.charts[i].chart;
                chart.setSelection($scope.table.getSelection());
            }
        });
        // Draw the chart
        $scope.redrawChart($scope.charts.length - 1, null);
    }
    // Draw the chart when it is first created or after user drops a new column into it.
    $scope.redrawChart = function(chartIndex, newColumnIndex) {    
        var chartItem = $scope.charts[chartIndex];
        var type = chartItem.type;
        var data = new google.visualization.DataView($scope.data);
        // Copy a list of columns for the DataView. Push them into charts[] after successfully drawn.
        var columns = chartItem.columns.slice(0);
        if (newColumnIndex != null) {
            columns.push(newColumnIndex);
        }
        // Copy options. Save them to charts[] after chart is successfully drawn.
        var options = Object.assign({}, chartItem.options);
        // Default filler columns.
        var dummyColumns = [{ calc: 0, type: "number" }, { calc: 0, type: "number" }];
        // Default beginning message.
        var message = columns.length == 0 ? "Drag in the category column." : "Drag in one or more series columns.";
        // Default options.
        options.legend = columns.length == 0 ? { position: "none" } : { position: "right" };
        if (columns.length > 0) {
            if (type == "Bar" || type == "Bar(stacked)") {
                if (!options.vAxis) {
                    options.vAxis = { };
                    options.vAxis.title = $scope.data.getColumnLabel(columns[0]);
                }
            }
            else if (type == "Bubble") {
                if (!options.hAxis) {
                    options.hAxis = { };
                    if (columns.length > 1) {
                        options.hAxis.title = $scope.data.getColumnLabel(columns[1]);
                    }
                }
                if (!options.vAxis) {
                    options.vAxis = { };
                    if (columns.length > 2) {
                        options.vAxis.title = $scope.data.getColumnLabel(columns[2]);
                    }
                }
            }
            else if (type == "Histogram") {

            }
            else {
                if (!options.hAxis) {
                    options.hAxis = { };
                    options.hAxis.title = $scope.data.getColumnLabel(columns[0]);
                }
            }
        }
        // Set the message and dummyColumns.
        switch (type) {
            case "Area":
                break;
            case "Area(stacked)":
                break;
            case "Bar":
                break;
            case "Bar(stacked)":
                break;
            case "Bubble":
                dummyColumns = [{ calc: 0, type: "string" }, { calc: 0, type: "number" }, { calc: 0, type: "number" }];
                break;
            case "Calendar":
                dummyColumns = [{ calc: function() { return new Date(); }, type: "date" }, {calc: 0, type: "number" }];
                message = columns.length == 0 ? "Drag in the date column." : "Drag in one or more series columns.";                            
                break;
            case "Candlestick":
                dummyColumns = [{ calc: 0, type: "number" }, { calc: 0, type: "number" }, { calc: 0, type: "number" }, { calc: 0, type: "number" }, { calc: 0, type: "number" }];
                message = 
                    columns.length == 0 ? "Drag in the category column." :
                    columns.length == 1 ? "Drag in the lower bounds column." : 
                    columns.length == 4 ? "Drag in the upper bounds column." :
                    "Drag in the opening and closing value columns.";
                break;
            case "Column":
                break;
            case "Combo":
                if (columns.length > 2) {
                    options.seriesType = "bars";
                    options.series = { };
                    options.series[columns.length - 2] = { type: "line" };
                }
                else if (columns.length == 1) {
                    message = "Drag and drop any number of series columns. The last series will be a line graph.";
                }
                break;
            case "Geo":
                message = columns.length == 0 ? "Drag in the location column." : "Drag in one or more series columns.";
                break;
            case "Histogram":
                message = "Drag in one or more series columns.";
                dummyColumns = [{ calc: 0, type: "number" }];
                break;
            case "Line":
                message = columns.length == 0 ? "Drag in the x-axis column." : "Drag in one or more series columns.";
                break;
            case "Org":
                dummyColumns = [{ calc: 0, type: "string" }, { calc: 0, type: "string" }];
                message = columns.length == 0 ? "Drag and drop the node ID column." : "Drag and drop the parent node ID column.";
                break;
            case "Pie":
                dummyColumns = [{ calc: 0, type: "string" }, { calc: 0, type: "number" }];
                break;
            case "Sankey":
                dummyColumns = [{ calc: 0, type: "string" }, { calc: 0, type: "string" }, { calc: 1, type: "number" }];
                message =
                    columns.length == 0 ? "Drag in the 1st category column." :
                    columns.length == 1 ? "Drag in the 2nd category column." :
                    "Drag in the weight column.";
                break;
            case "Scatter":
                message = columns.length == 0 ? "Drag in the x-axis column." : "Drag in one or more series columns.";
                break;
            case "Stepped Area":
                break;
            case "Timeline":
                dummyColumns = [{ calc: 0, type: "number" }, { calc: function() { return new Date(); }, type: "date" }];
                message = 
                    columns.length == 0 ? "Drag in the category column." : 
                    columns.length == 1 ? "Drag in the start date or time column." : 
                    "Drag in the end date or time column.";
                break;
            case "Treemap":
                dummyColumns = [{ calc: 0, type: "string" }, { calc: 0, type: "string" }, { calc: 0, type: "number" }, { calc: 0, type: "number" }];
                message = 
                    columns.length == 0 ? "Drag in the category column." :
                    columns.length == 1 ? "Drag in the parent category column." :
                    "Drag in one or more value columns.";
                break;
        }
        // Get rid of message if there are enough data.
        if (columns.length >= dummyColumns.length) {
            message = "";
        }
        // Fill missing columns so that the Chart can still draw.
        for (var i = 0; i < columns.length; i++) {
            if (type == "Calendar" && i == 0) {
                dummyColumns[0] = { calc: function(table, row) { return new Date(table.getValue(row, columns[0])); }, type: "date"};
            }
            else if (type == "Timeline" && i == 1) {
                dummyColumns[1] = { calc: function(table, row) { return new Date(table.getValue(row, columns[1])); }, type: "date"};
            }
            else {
                dummyColumns[i] = columns[i];
            }
        }
        data.setColumns(dummyColumns);                    
        google.visualization.events.addListener(chartItem.chart, "ready", function() {
            chartItem.options = options;
            chartItem.columns = columns;
        });
        chartItem.chart.draw(data, options);
        document.getElementsByClassName("chart-instructions")[chartIndex].innerHTML = message;
    }
    // Drag and drop table header into a Google chart.
    $scope.drag = function(event) {
        var el = event.target;
        var siblings = Array.prototype.slice.call(el.parentElement.children);
        event.dataTransfer.setData("text/plain", siblings.indexOf(el) - 1);
    }
    $scope.allowDrop = function(event) {
        event.preventDefault();
    }
    $scope.drop = function(event) { 
        var newColumnIndex = Number(event.dataTransfer.getData("text"));
        event.preventDefault();
        event.stopPropagation();
        var div = event.target.closest(".chart-container-parent");
        var siblings = Array.prototype.slice.call(div.parentElement.children);
        var index = siblings.indexOf(div);
        $scope.redrawChart(index, newColumnIndex);
    }
    // Change the size of a chart.
    $scope.resizeCharts = function(element, increase) {
        var div = element.closest(".chart-container-parent");
        var siblings = Array.prototype.slice.call(div.parentElement.children);
        var index = siblings.indexOf(div);
        var chart = $scope.charts[index];
        var width = div.offsetWidth;
        if (increase) {
            element.style.width = (width * 1.25) + "px";
            element.style.height = (width * 1.25) + "px";
        }
        else {
            element.style.width = (width * .75) + "px";
            element.style.height = (width * .75) + "px";
        }
        $scope.redrawChart(index, null);
    }
});
// Use a custom filter because it is easier to read than inline.
app.filter("columnFilter", function() {
   return function(input, ordinal, custom) {
       if (!input) {
           return input;
       }
       var result = new Array();
       if (custom == true) {
           for (var i = 0; i < input.length; i++) {
               if (input[i].customColumn == true) {
                   result.push(input[i]);
               }
           }
           return result;
       }
       else {
           for (var i = 0; i < input.length; i++) {
               if (!input[i].customColumn || input[i].customColumn == false) {
                   result.push(input[i]);
               }
           }
           var l = Math.ceil(result.length/3);
           var start = l * ordinal;
           return result.slice(start, l + start);
       }
   };
});