<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
	<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <title>PathS</title>
	
    <link href="css/index.css" rel="stylesheet" type="text/css">
    <link href="css/atooltip.css" rel="stylesheet" type="text/css">
    <link href="css/ui.switchbutton.css" rel="stylesheet" type="text/css">
	<link rel="stylesheet" type="text/css" href="css/jquery.datetimepicker.css" >
	<link rel="stylesheet" href="css/jquery-ui.css">
	
	<script src="js/customFormat.js"></script>
	<script src="js/jquery.min.js"></script>
	<script src="js/jquery-ui.js"></script>
	<script type="text/javascript" src="js/jquery.tmpl.min.js"></script>
	<script src="js/jquery.switchbutton.js"></script>
	<script type="text/javascript" src="js/jquery.atooltip.min.js"></script>  
	<script src="js/highstock.js"></script>
	<script src="js/jquery.datetimepicker.js"></script>
    <script src="https://maps.googleapis.com/maps/api/js?v=3.exp&amp;libraries=visualization"></script>
	<script>
		var samplesData; // Lista di tutti i campionamenti
		var samplesCoordsData = []; // Lista delle coordinate dei campionamenti per la mappa
		
		// Per il video
		var sliderStep = 60;
		var numberOfTimeRanges = 1440 / sliderStep;
		var samplesIndexes = [numberOfTimeRanges]; // Lista degli indici utilizzata per mostrare i campionamenti divisi per l'orario
		var samplesNoiseTimedData = [numberOfTimeRanges];
		var samplesTimedDataGraph = [numberOfTimeRanges];
		var samplesLightTimedData = [numberOfTimeRanges];
		
		// Per le immagini
		var samplesLightData = [];
		var samplesNoiseData = [];
		var samplesGraphData = [];
		
		var currentArrayIndex;
		
		var graphDataIds = [];
		var invisibleMarkers = [];
		var map, heatmap;
		var bounds;
		var currentCoord = null;
		var marker, markerCircle;
		var currentShown = -1; // 0 = Samples, 1 = Light, 2 = Noise
		var hours = "00", minutes = "00";
		var offsetMS;
		
		var mindate = -1;
		var maxdate = -1;
		var minhour = 0;
		var maxhour = 24*sliderStep;
		
		var lightGraph, noiseGraph;
		
		var userLocation;
		
		var isVideoSelected = false;
		var isTimeSlot = false;
		
		function Comparator(a,b){
			if (a[0] < b[0]) return -1;
			if (a[0] > b[0]) return 1;
			return 0;
		}

		function getSamples() {
			if (window.XMLHttpRequest) {
				// code for IE7+, Firefox, Chrome, Opera, Safari
				xmlhttp=new XMLHttpRequest();
			} else { // code for IE6, IE5
				xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
			}
			xmlhttp.onreadystatechange=function() {
				if (xmlhttp.readyState==4 && xmlhttp.status==200) {
					samplesData = JSON.parse(xmlhttp.responseText);
					for (var i = 0; i < samplesData.length; i++) {
						var jsonCoord = samplesData[i]; 
						//[0]=id, [1]=lat, [2]=lng, [3]=timestamp, [4]=type1, [5]=value1, [6]=type2, [7]=value2
						var jsonCoordLight, jsonCoordNoise;
						
						var light = getLightFromJson(jsonCoord);
						var noise = getNoiseFromJson(jsonCoord);
						
						var ms=Date.parse(jsonCoord[3])-offsetMS;
						var date = new Date(ms);
						if(mindate == -1 || mindate > ms)
							mindate=ms;
						if(maxdate == -1 || maxdate < ms)
							maxdate=ms;
						var hourtime = date.customFormat("#DD#/#MM#/#YYYY# #hhh#:#mm#:#ss#");
						var name = "LIGHT: "+light+", NOISE: "+noise+" "+hourtime;
						var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
						samplesCoordsData.push(coord);
						
						bounds.extend(coord);
						addInvisibleMarker(null, name, coord);
						
						callback_initialize();
					}
				}
			}
			//$('#wait').show();
			xmlhttp.open("GET","getSamples.php",true);
			xmlhttp.send();
		}
		
		function addInvisibleMarker(map, name, latlng){
			var url = document.URL.substring(0,document.URL.indexOf("#"));
			if(url=="")
				url = document.URL;
			var image = new google.maps.MarkerImage(url+'img/invisible-marker.png',
				null, 
				null,
				new google.maps.Point(10, 10)
			);
			var mark = new google.maps.Marker({
				map: map,
				position: latlng,
				title: name,
				icon: image
			});
			google.maps.event.addListener(mark, 'click', function(){
				clickFunction(mark.getPosition());
			});
			invisibleMarkers.push(mark);
		}
		
		function setAllMap(map) {
			for (var i = 0; i < invisibleMarkers.length; i++) {
				invisibleMarkers[i].setMap(map);
			}
		}
		
		function setMarkerMap(index,map) {
			invisibleMarkers[index].setMap(map);
		}
		
		function distanceInMeters(lat1, lon1, lat2, lon2){
			var R = 6371;
			var a = 0.5 - Math.cos((lat2 - lat1) * Math.PI / 180)/2 + Math.cos(lat1 * Math.PI / 180) * 
					Math.cos(lat2 * Math.PI / 180) * (1 - Math.cos((lon2 - lon1) * Math.PI / 180))/2;
			return R * 2 * Math.asin(Math.sqrt(a)) * 1000;
		}
				
		function showGraph() {
			if(currentCoord != null){
				graphData = [];
				var samplesDataTemp;
				if(currentShown == 0){
					if(isVideoSelected) samplesDataTemp = samplesTimedDataGraph[currentArrayIndex];
					else samplesDataTemp = samplesData;
				} 
				else if(currentShown == 1 || currentShown == 2){
					if(isVideoSelected) samplesDataTemp = samplesTimedDataGraph[currentArrayIndex];
					else samplesDataTemp = samplesGraphData;
				}
				
				for (var i = 0; i < samplesDataTemp.length; i++) {
					var jsonCoord = samplesDataTemp[i];
					var radius = document.getElementById('radiusM').value;
					var dist = distanceInMeters(currentCoord.lat(), currentCoord.lng(), jsonCoord[1], jsonCoord[2]);
					if(dist <= radius){
						var ms=Date.parse(jsonCoord[3])-offsetMS;
						var date = new Date(ms);
						var check = false;
						if(isTimeSlot == true)
							check = (ms >= mindate && ms <= maxdate && date.getHours() >= (minhour/60) && date.getHours() <= ((maxhour-60)/60));
						else
							check = (ms >= mindate && ms <= maxdate);
						if(check)
							graphData.push(jsonCoord); // Inserisco il sample nell'array graphData
					}
				}
				if(graphData.length != 0){
					var lightData = [];
					var noiseData = [];
					var plotXLines = [];
					var lastDate = 0;
					for (var i = 0; i < graphData.length; i++) {
						var label = graphData[i];
						//[0]=id, [1]=lat, [2]=lng, [3]=timestamp, [4]=type1, [5]=value1, [6]=type2, [7]=value2
						var ms=Date.parse(label[3])-offsetMS;
						var date = new Date(ms);
						var hourtime = date.customFormat("#DD#/#MM#/#YYYY#");
						if(lastDate == 0 || lastDate != hourtime){
							plotXLines.push(ms);
							lastDate = hourtime;
						}
						var light = getLightFromJson(label);
						var noise = getNoiseFromJson(label);
						var toAddLight = [];
						var toAddNoise = [];
						toAddLight.push(ms);
						toAddLight.push(light);
						toAddNoise.push(ms);
						toAddNoise.push(noise);
						lightData.push(toAddLight);
						noiseData.push(toAddNoise);
					}
					lightData = lightData.sort(Comparator);
					noiseData = noiseData.sort(Comparator);
					
					$('#numSamples').html("Number of samples: "+lightData.length);
					
					lightGraph.series[0].setData(lightData);
					noiseGraph.series[0].setData(noiseData);
					lightGraph.xAxis[0].setExtremes();
					noiseGraph.xAxis[0].setExtremes();
					
					lightGraph.xAxis[0].removePlotLine('light-plotLine');
					noiseGraph.xAxis[0].removePlotLine('noise-plotLine');
					for(var i=0; i<plotXLines.length; i++){
						var d = new Date(plotXLines[i]);
						var datetext = d.customFormat("#DD#/#MM#");
						lightGraph.xAxis[0].addPlotLine({
							id: 'light-plotLine',
							value: plotXLines[i],
							width: 1,
							color: 'green',
							dashStyle: 'dash'
						});
						noiseGraph.xAxis[0].addPlotLine({
							id: 'noise-plotLine',
							value: plotXLines[i],
							width: 1,
							color: 'green',
							dashStyle: 'dash'
						});
					}
				}
				else{
					$('#numSamples').html("Number of samples: 0");
					lightGraph.series[0].setData([]);
					noiseGraph.series[0].setData([]);
				}
			}
		}

		var lastselectedminhour = -1;
		var lastselectedmaxhour = -1;
		function initialize() {
			bounds = new google.maps.LatLngBounds();
			var mapOptions = {
				mapTypeId: google.maps.MapTypeId.TERRAIN
			};
			map = new google.maps.Map(document.getElementById('map-canvas'), mapOptions);
			heatmap = new google.maps.visualization.HeatmapLayer();
			heatmap.setMap(map);
			heatmap.set('maxIntensity', 8);
			
			var date = new Date();
			offsetMS = date.getTimezoneOffset()*60*1000;
					
			var tabs =  $(".tabs li a");
			tabs.click(function() {
				var panels = this.hash.replace('/','');
				tabs.removeClass("active");
				$(this).addClass("active");
				$("#panels").find('p').hide();
				if(this.hash == "#/samples"){
					toggleSamples();
					$("#tabTitle").html("Number and location of samples");
					jQuery("#toInsert").detach().appendTo('#samples');
				}
				else if(this.hash == "#/light"){
					toggleLight();
					$("#tabTitle").html("Light values");
					jQuery("#toInsert").detach().appendTo('#light');
				}
				else if(this.hash == "#/noise"){
					toggleNoise();
					$("#tabTitle").html("Noise values");
					jQuery("#toInsert").detach().appendTo('#noise');
				}
				$(panels).fadeIn(0);
			});
			
			getSamples();
			
		}
		
		function callback_initialize(){
			createLightAndNoiseArrays();
			createTimedArrays();
			jQuery("#toInsert").detach().appendTo('#samples');
			toggleSamples();
			map.fitBounds(bounds);
						
			var min = new Date(mindate);
			min.setHours(0);
			min.setMinutes(0);
			min.setSeconds(0);
			min.setMilliseconds(0);
			var mintime = min.customFormat("#YYYY#/#MM#/#DD# #hhh#:#mm#");
			$( "#datepickerfrom" ).datetimepicker({ 
				value: mintime,
				formatDate:'d/m/Y',
				onChangeDateTime:function(dp,$input){
					removeMarker();
					var datefrom = $input.val();
					var msdate = new Date();
					msdate.setFullYear(Number(datefrom.split("/")[0]), Number(datefrom.split("/")[1]-1), Number(datefrom.split("/")[2].split(" ")[0]));
					msdate.setHours(Number(datefrom.split(" ")[1].split(":")[0]));
					msdate.setMinutes(Number(datefrom.split(" ")[1].split(":")[1]));
					msdate.setSeconds(0);
					msdate.setMilliseconds(0);
					var tmpmindate=msdate.getTime();
					if(tmpmindate > maxdate){
						alert("Empty range");
						$( "#datepickerfrom" ).datetimepicker({ value: mintime});
					}
					else{
						mindate = tmpmindate;
					}
					var selectedtimestart = Number($( "#datepickerfrom" ).val().split(" ")[1].split(":")[0]);
					var selectedmins = Number($( "#datepickerfrom" ).val().split(" ")[1].split(":")[1]);
					if(selectedmins > 30)
						selectedtimestart++;
					minhour = selectedtimestart * 60;
					if(lastselectedminhour==-1) lastselectedminhour=minhour;
					if(maxhour <= minhour){
						alert("The selected hour value must be smaller than the other one");
						minhour = lastselectedmaxhour;
						$( "#datepickerfrom" ).datetimepicker({ value: maxtime});
					}
					lastselectedminhour=minhour;
					$('#leftSlider').css('width', (minhour*100/1440) +'%');
					$('#slider-time').slider('value',minhour);
					slideToggleFunction(minhour);
					
					createLightAndNoiseArrays();
					if(currentShown == 0) toggleSamples();
					else if(currentShown == 1) toggleLight();
					else if(currentShown == 2) toggleNoise();

				}
			});
			var max = new Date(maxdate);
			max.setHours(23);
			max.setMinutes(59);
			max.setSeconds(59);
			max.setMilliseconds(999);
			var maxtime = max.customFormat("#YYYY#/#MM#/#DD# #hhh#:#mm#");
			$( "#datepickerto" ).datetimepicker({ 
				value: maxtime,
				formatDate:'d/m/Y',
				onChangeDateTime:function(dp,$input){
					removeMarker();
					var dateto = $input.val();
					var msdate = new Date();
					msdate.setHours(Number(dateto.split(" ")[1].split(":")[0]));
					msdate.setMinutes(Number(dateto.split(" ")[1].split(":")[1]));
					msdate.setSeconds(59);
					msdate.setMilliseconds(999);
					msdate.setFullYear(Number(dateto.split("/")[0]), Number(dateto.split("/")[1]-1), Number(dateto.split("/")[2].split(" ")[0]));
					var tmpmaxdate=msdate.getTime();
					if(tmpmaxdate < mindate){
						alert("Empty range");
						$( "#datepickerto" ).datetimepicker({ value: maxtime});
					}
					else maxdate = tmpmaxdate;
					var selectedtimeend =  Number($( "#datepickerto" ).val().split(" ")[1].split(":")[0]);
					var selectedmins = Number($( "#datepickerto" ).val().split(" ")[1].split(":")[1]);
					if(selectedmins > 30)
						selectedtimeend++;
					maxhour = selectedtimeend * 60;
					if(lastselectedmaxhour==-1) lastselectedmaxhour=maxhour;
					if((maxhour) <= minhour){
						alert("The selected hour value must be greater than the other one");
						maxhour = lastselectedmaxhour;
						$( "#datepickerto" ).datetimepicker({ value: maxtime});
					}
					lastselectedmaxhour=maxhour;
					$('#rightSlider').css('width', 100 - ((maxhour)*100/1440) +'%');
					$('#slider-time').slider('value',minhour);
					slideToggleFunction(minhour);
					
					createLightAndNoiseArrays();
					if(currentShown == 0) toggleSamples();
					else if(currentShown == 1) toggleLight();
					else if(currentShown == 2) toggleNoise();
				}
			});
			
			if(sliderStep<=60)
				$('#time').html("00:00 - 00:"+(sliderStep-1));
			else
				$('#time').html("00:00 - "+parseInt((sliderStep-1)/60)+":59");
			$('#numSamples').html("Number of samples: 0");
			
			var width_but1 = $("#samplesButton").width();
			var width_but2 = $("#lightButton").width();
			var width_but3 = $("#noiseButton").width();
			var max = width_but1;
			if(width_but2 > max) max=width_but2;
			if(width_but3 > max) max=width_but3;
			$("#samplesButton").width(max);
			$("#lightButton").width(max);
			$("#noiseButton").width(max);
			
			Highcharts.setOptions({
				global : {
					useUTC : false
				}
			});
			$('#graphLight').highcharts('StockChart', {
				rangeSelector: {
					selected: 5,
					allButtonsEnabled: true
				},
				chart: {
					zoomType: 'x'
				},
				title: {
					text: 'LIGHT'
				},
				xAxis: {
					title: {
						text: 'Timestamp'
					},
					tickmarkPlacement: 'on'
				},
				yAxis: {
					title: {
						text: 'Valore',
					},
					min: 0,
					max: 100
				},
				plotOptions: {
					series: {
						turboThreshold: 0
					}
				},
				series: [{
					type: 'column',
					name: 'LIGHT',
					data: []
				}]
			});
			$('#graphNoise').highcharts('StockChart', {
				rangeSelector: {
					selected: 5,
					allButtonsEnabled: true
				},
				chart: {
					zoomType: 'x'
				},
				title: {
					text: 'NOISE'
				},
				xAxis: {
					title: {
						text: 'Timestamp'
					},
					tickmarkPlacement: 'on'
				},
				yAxis: {
					title: {
						text: 'Valore',
					},
					min: 0,
					max: 100
				},
				plotOptions: {
					series: {
						turboThreshold: 0
					}
				},
				series: [{
					type: 'column',
					name: 'NOISE',
					data: []
				}]
			});
			lightGraph = $('#graphLight').highcharts();
			noiseGraph = $('#graphNoise').highcharts();
			
			$('#graphLightToggle').click(function() {
				  $('#graphLight').slideToggle();
				  return false;
			});
			$('#graphNoiseToggle').click(function() {
				  $('#graphNoise').slideToggle();
				  return false;
			});
			centerLocation(true);
			
			$("#radiusM").bind("input", function() {
				var val = parseFloat(document.getElementById('radiusM').value);
				if(markerCircle != null){
					markerCircle.setRadius(val*2);
					showGraph();
				}
			});
			
			$('.myButton').aToolTip({  
				// no need to change/override 
				toolTipId: 'aToolTip',  
				// ok to override  
				fixed: false,                   // Set true to activate fixed position  
				clickIt: false,                 // set to true for click activated tooltip  
				inSpeed: 200,                   // Speed tooltip fades in  
				outSpeed: 100,                  // Speed tooltip fades out  
				tipContent: '',                 // Pass in content or it will use objects 'title' attribute  
				toolTipClass: 'defaultTheme',   // Set class name for custom theme/styles  
				xOffset: 5,                     // x position  
				yOffset: 5,                     // y position  
				onShow: null,                   // callback function that fires after atooltip has shown  
				onHide: null                    // callback function that fires after atooltip has faded out      
			});
			$('#infoRadius').aToolTip({  
				// no need to change/override 
				toolTipId: 'aToolTip',  
				// ok to override  
				fixed: false,                   // Set true to activate fixed position  
				clickIt: false,                 // set to true for click activated tooltip  
				inSpeed: 200,                   // Speed tooltip fades in  
				outSpeed: 100,                  // Speed tooltip fades out  
				tipContent: '',                 // Pass in content or it will use objects 'title' attribute  
				toolTipClass: 'defaultTheme',   // Set class name for custom theme/styles  
				xOffset: 5,                     // x position  
				yOffset: 5,                     // y position  
				onShow: null,                   // callback function that fires after atooltip has shown  
				onHide: null                    // callback function that fires after atooltip has faded out      
			});
			$('#timetypediv').aToolTip({  
				// no need to change/override 
				toolTipId: 'aToolTip',  
				// ok to override  
				fixed: false,                   // Set true to activate fixed position  
				clickIt: false,                 // set to true for click activated tooltip  
				inSpeed: 200,                   // Speed tooltip fades in  
				outSpeed: 100,                  // Speed tooltip fades out  
				tipContent: '',                 // Pass in content or it will use objects 'title' attribute  
				toolTipClass: 'defaultTheme',   // Set class name for custom theme/styles  
				xOffset: 5,                     // x position  
				yOffset: 5,                     // y position  
				onShow: null,                   // callback function that fires after atooltip has shown  
				onHide: null                    // callback function that fires after atooltip has faded out      
			});
			$( "#timestep" ).change(function() {
				if((maxhour-minhour)<parseInt($(this).val())){
					$('option[value='+lasttimestep+']').attr('selected', 'selected');
					alert("The step is too long for this interval");
				}
				else{
					sliderStep =  parseInt($(this).val());
					lasttimestep = sliderStep;
					createTimedArrays();
					$('#slider-time').slider('option', 'step', sliderStep);
					$('#slider-time').slider('option', 'max', 1440-sliderStep);
					$('#slider-time').slider('value',minhour);
					slideToggleFunction(minhour);
				}
			});
		}
		
		var lasttimestep = 60;
		
		function clickFunction(coord){
			currentCoord = coord;
			var lat = coord.lat();
			var lng = coord.lng();
			map.panTo(coord);
			var zoom = map.getZoom();
			if(zoom < 10) zoom = 10;
			else if(zoom < 14) zoom = 14;
			else if(zoom < 18) zoom = 18;
			setTimeout("map.setZoom("+zoom+")",500);
			if(marker != null){
				marker.setMap(null);
				markerCircle.setMap(null);
			}
			marker = new google.maps.Marker({
				position: coord,
				map: map,
				title: "Position: "+lat+", "+lng
			});
			var circleOptions = {
			  strokeColor: '#FF0000',
			  strokeOpacity: 0.8,
			  strokeWeight: 2,
			  fillColor: '#FF0000',
			  fillOpacity: 0.35,
			  map: map,
			  center: currentCoord,
			  radius: parseFloat(document.getElementById('radiusM').value)*2
			};
			// Add the circle for this city to the map.
			markerCircle = new google.maps.Circle(circleOptions);
			document.getElementById("removeMarkerId").style.display = "block";
			showGraph();
		}
		
		function slideToggleFunction(value) {
			hours = Math.floor(value / 60);
			minutes = value - (hours * 60);

			if(hours < 10) hours = '0' + hours;
			if(minutes.length < 10) minutes = '0' + minutes;
			if(minutes == 0) minutes = '00';
			
			var hourend = parseInt(hours)+parseInt((sliderStep-1)/60);
			var minend = parseInt(minutes)+parseInt((sliderStep-1)%60);
			if(hourend < 10) hourend = '0' + hourend;
			if(minend.length < 10) minend = '0' + minend;
			if(minend == 0) minend = '00';
			
			$('#time').html(hours+':'+minutes+" - "+hourend+':'+minend);
			
			updateDataInTimeRange();
			showGraph();
		}
		
		var lastslidervalue = -1;
		jQuery(function() {
			jQuery('#slider-time').slider({
				range: false,
				min: 0,
				max: 1440-sliderStep,
				animate: "slow",
				step: sliderStep,
				slide: function(e, ui) { 
					if(lastslidervalue==-1) lastslidervalue = ui.value;
					if(ui.value >= minhour && ui.value <= (maxhour-sliderStep)){
						lastslidervalue=ui.value;
						slideToggleFunction(ui.value);
					}
					else{
						return false;
					}
					
				}
			})
			.append('<div id="leftSlider" style="width: 0%"></div>')
			.append('<div id="rightSlider" style="width: 0%"></div>');
		});
		
		function getLightFromJson(json){
			var light;
			if(json[4] == 'LIGHT' && json[6] == 'NOISE'){
				light = parseFloat(json[5]);
			}
			else if(json[6] == 'LIGHT' && json[4] == 'NOISE'){
				light = parseFloat(json[7]);
			}
			if(light<0 || light == 0.0001) 
				light = 0.0;
			if(light == 100.0001)
				light = 100.0;
			return light;
		}
		function getNoiseFromJson(json){
			var noise;
			if(json[4] == 'LIGHT' && json[6] == 'NOISE'){
				noise = parseFloat(json[7]);
			}
			else if(json[6] == 'LIGHT' && json[4] == 'NOISE'){
				noise = parseFloat(json[5]);
			}
			if(noise<0 || noise == 0.0001) 
				noise = 0.0;
			if(noise == 100.0001)
				noise = 100.0;
			return noise;
		}
		
		function createLightAndNoiseArrays(){
			samplesLightData = [];
			samplesNoiseData = [];
			samplesGraphData = [];
			for(var i=0; i<samplesData.length; i++){
				var jsonCoord = samplesData[i];
				
				var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
				var light = getLightFromJson(jsonCoord);
				var noise = getNoiseFromJson(jsonCoord);
				var ms=Date.parse(jsonCoord[3])-offsetMS;
				var date = new Date(ms);
				var check = false;
				if(isTimeSlot == true)
					check = (ms >= mindate && ms <= maxdate && date.getHours() >= (minhour/60) && date.getHours() <= ((maxhour-60)/60));
				else
					check = (ms >= mindate && ms <= maxdate);
				if(check){
					var checkAdd = true;
					samplesGraphData.push(jsonCoord);
					for(var j=0; j<samplesLightData.length; j++){
						var toCheck = samplesLightData[j];
						var d = distanceInMeters(coord.lat(),coord.lng(),
												 toCheck.location.lat(),toCheck.location.lng());
						if(d<10){
							checkAdd = false;
							var w1 = light / 10;
							var w2 = noise / 10;
							samplesLightData[j].weight = (samplesLightData[j].weight + w1)/2;
							samplesLightData[j].indexes.push(i);
							samplesNoiseData[j].weight = (samplesNoiseData[j].weight + w2)/2;
							samplesNoiseData[j].indexes.push(i);
						}
					}
					if(checkAdd){
						samplesLightData.push({location:coord, weight: light/10});
						samplesLightData[samplesLightData.length-1].indexes = [];
						samplesLightData[samplesLightData.length-1].indexes.push(i);
						samplesNoiseData.push({location:coord, weight: noise/10});
						samplesNoiseData[samplesNoiseData.length-1].indexes = [];
						samplesNoiseData[samplesNoiseData.length-1].indexes.push(i);
					}
				}
			}
		}
		
		function createTimedArrays(){
			numberOfTimeRanges = 1440 / sliderStep;
			samplesIndexes = [numberOfTimeRanges];
			samplesNoiseTimedData = [numberOfTimeRanges];
			samplesTimedDataGraph = [numberOfTimeRanges];
			samplesLightTimedData = [numberOfTimeRanges];
			for(var i=0; i<numberOfTimeRanges; i++) {
				samplesLightTimedData[i] = [];
				samplesTimedDataGraph[i] = [];
				samplesNoiseTimedData[i] = [];
				samplesIndexes[i] = [];
			}
			for(var i=0; i<samplesData.length; i++){
				var jsonCoord = samplesData[i];
				var ms=Date.parse(jsonCoord[3])-offsetMS;
				var date = new Date(ms);
				var hourtime = date.customFormat("#hhh#:#mm#:#ss#");
				var sampleHour = hourtime.split(":")[0];
				var sampleMins = hourtime.split(":")[1];
				var arrayIndex = parseInt(sampleHour*(60/sliderStep) + parseInt(sampleMins/sliderStep));
				
				var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
				var light = getLightFromJson(jsonCoord);
				var noise = getNoiseFromJson(jsonCoord);
				
				//samplesTimedDataGraph[arrayIndex].push({location:coord});
				samplesTimedDataGraph[arrayIndex].push(jsonCoord);
				
				var checkAdd = true;
				for(var j=0; j<samplesIndexes[arrayIndex].length; j++){
					var toCheck = samplesIndexes[arrayIndex][j];
					var d = distanceInMeters(samplesCoordsData[i].lat(),samplesCoordsData[i].lng(),
											 samplesCoordsData[toCheck].lat(),samplesCoordsData[toCheck].lng());
					if(d<2){
						checkAdd = false;
						var light2 = getLightFromJson(samplesData[toCheck]);
						var noise2 = getNoiseFromJson(samplesData[toCheck]);
						var w1 = light2 / 10;
						var w2 = noise2 / 10;
						samplesLightTimedData[arrayIndex][j].weight = (samplesLightTimedData[arrayIndex][j].weight + w1)/2;
						samplesNoiseTimedData[arrayIndex][j].weight = (samplesNoiseTimedData[arrayIndex][j].weight + w2)/2;
					}
				}
				if(checkAdd){
					samplesIndexes[arrayIndex].push(i);
					samplesLightTimedData[arrayIndex].push({location:coord, weight: light/10});
					samplesNoiseTimedData[arrayIndex].push({location:coord, weight: noise/10});
				}
			}
			/*for(var i=0; i<numberOfTimeRanges; i++){
				console.log("#"+i+": "+samplesLightTimedData[i].length);
			}*/
		}
		
		function updateDataInTimeRange(){
			heatmap.setData([]);
			setAllMap(null);
			currentArrayIndex = parseInt(hours)*(60/sliderStep) + parseInt(minutes/sliderStep);
			if(currentShown == 0){
				var samplesDataTimeRange = [];
				for(var i=0; i<samplesIndexes[currentArrayIndex].length; i++){
					var jsonCoord = samplesData[samplesIndexes[currentArrayIndex][i]];
					var ms=Date.parse(jsonCoord[3])-offsetMS;
					var date = new Date(ms);
					var check = false;
					if(isTimeSlot == true)
						check = (ms >= mindate && ms <= maxdate && date.getHours() >= (minhour/60) && date.getHours() <= ((maxhour-60)/60));
					else
						check = (ms >= mindate && ms <= maxdate);
					if(check){
						var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
						setMarkerMap(samplesIndexes[currentArrayIndex][i],map);
						var coord = new google.maps.LatLng(samplesTimedDataGraph[currentArrayIndex][i][1], samplesTimedDataGraph[currentArrayIndex][i][2]);
						samplesDataTimeRange.push(coord);
					}
				}
				var dataToSet = new google.maps.MVCArray(samplesDataTimeRange);
				heatmap.setData(dataToSet);
			}
			else if(currentShown == 1 || currentShown == 2){
				var lightDataTimeRange = [];
				var noiseDataTimeRange = [];
				for(var i=0; i<samplesIndexes[currentArrayIndex].length; i++){
					var jsonCoord = samplesData[samplesIndexes[currentArrayIndex][i]];
					var ms=Date.parse(jsonCoord[3])-offsetMS;
					var date = new Date(ms);
					var check = false;
					if(isTimeSlot == true)
						check = (ms >= mindate && ms <= maxdate && date.getHours() >= (minhour/60) && date.getHours() <= ((maxhour-60)/60));
					else
						check = (ms >= mindate && ms <= maxdate);
					if(check){
						var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
						setMarkerMap(samplesIndexes[currentArrayIndex][i],map);
						if(currentShown == 1)
							lightDataTimeRange.push(samplesLightTimedData[currentArrayIndex][i]);
						else if(currentShown == 2)
							noiseDataTimeRange.push(samplesNoiseTimedData[currentArrayIndex][i]);
					}
				}
				var dataToSet;
				if(currentShown == 1){
					dataToSet = new google.maps.MVCArray(lightDataTimeRange);
				}
				else if(currentShown == 2){
					dataToSet = new google.maps.MVCArray(noiseDataTimeRange);
				}
				heatmap.setData(dataToSet);
			}
		}
		
		function toggleSamples() {
			$("#samplesButton").addClass("buttonActive");
			$("#lightButton").removeClass("buttonActive");
			$("#noiseButton").removeClass("buttonActive");
			var samplesCoordsDataTimeRange = [];
			currentShown = 0;
			heatmap.setData([]);
			setAllMap(null);
			removeMarker();
			if(isVideoSelected == true){
				updateDataInTimeRange();
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
			}
			else{
				for (var i = 0; i < samplesData.length; i++) {
					var jsonCoord = samplesData[i]; 
					//[0]=id, [1]=lat, [2]=lng, [3]=timestamp, [4]=type1, [5]=value1, [6]=type2, [7]=value2
					var ms=Date.parse(jsonCoord[3])-offsetMS;
					var date = new Date(ms);
					var check = false;
					if(isTimeSlot == true)
						check = (ms >= mindate && ms <= maxdate && date.getHours() >= (minhour/60) && date.getHours() <= ((maxhour-60)/60));
					else
						check = (ms >= mindate && ms <= maxdate);
					if(check){
						var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
						setMarkerMap(i,map);
						samplesCoordsDataTimeRange.push(coord);
					}
				}
				if(samplesCoordsDataTimeRange.length == 0)
					alert("There are no samples in this range");
				var pointArray = new google.maps.MVCArray(samplesCoordsDataTimeRange);
				heatmap.setData(pointArray);
			}
		}
		
		function toggleLight() {
			$("#samplesButton").removeClass("buttonActive");
			$("#lightButton").addClass("buttonActive");
			$("#noiseButton").removeClass("buttonActive");
			currentShown = 1;
			heatmap.setData([]);
			setAllMap(null);
			removeMarker();
			if(isVideoSelected == true){
				updateDataInTimeRange();
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
			}
			else{
				for (var i = 0; i < samplesLightData.length; i++) {
					for(var j=0; j<samplesLightData[i].indexes.length; j++){
						setMarkerMap(samplesLightData[i].indexes[j],map);
					}
				}
				var pointArray = new google.maps.MVCArray(samplesLightData);
				heatmap.setData(pointArray);
			}
		}
		
		function toggleNoise() {
			$("#samplesButton").removeClass("buttonActive");
			$("#lightButton").removeClass("buttonActive");
			$("#noiseButton").addClass("buttonActive");
			currentShown = 2;
			heatmap.setData([]);
			setAllMap(null);
			removeMarker();
			if(isVideoSelected == true){
				updateDataInTimeRange();
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
			}
			else{
				for (var i = 0; i < samplesNoiseData.length; i++) {
					for(var j=0; j<samplesNoiseData[i].indexes.length; j++){
						setMarkerMap(samplesNoiseData[i].indexes[j],map);
					}
				}
				var pointArray = new google.maps.MVCArray(samplesNoiseData);
				heatmap.setData(pointArray);
			}
		}
		
		function removeMarker() {
			if(marker != null){
				marker.setMap(null);
				markerCircle.setMap(null);
				document.getElementById("removeMarkerId").style.display = "none";
				currentCoord = null;
				$('#numSamples').html("Number of samples: 0");
				lightGraph.series[0].setData([]);
				noiseGraph.series[0].setData([]);
			}
		}
		
		var isFullscreen = false;
		var isPlaying = false;
		var timeoutVar;
		var slider_vel = 1000;
		
		function fullscreen() {
			if(isFullscreen){
				document.getElementById("contentGraphs").style.display = "table-cell";
				document.getElementById("contentMap").width = "50%";
				document.getElementById("controlButtons").style.right = "51%";
				document.getElementById("removeMarkerId").style.right = "51%";
				document.getElementById("bottomSlider").style.right = "55%";
				document.getElementById("bottomSlider").style.right = "55%";
				document.getElementById("image_fullscren").src="img/fullscreen_in.png";
			}
			else{
				document.getElementById("contentGraphs").style.display = "none";
				document.getElementById("contentMap").width = "100%";
				document.getElementById("controlButtons").style.right = "2%";
				document.getElementById("removeMarkerId").style.right = "2%";
				document.getElementById("bottomSlider").style.right = "13%";
				document.getElementById("image_fullscren").src="img/fullscreen_out.png";
			}
			google.maps.event.trigger(map, 'resize');
			if(marker != null){
				map.setCenter(currentCoord);
			}
			isFullscreen = !isFullscreen;
		}
		
		function playpause(){
			if(isPlaying){
				document.getElementById("img-playpause").src = "img/play.png";
				window.clearInterval(timeoutVar);
			}
			else{
				document.getElementById("img-playpause").src = "img/pause.png";
				timeoutVar=setInterval(
					function () {
						var val = $('#slider-time').slider("option", "value");
						val+=sliderStep;
						if(val>=1440 || val <= minhour || val > (maxhour-sliderStep)){
							$('#slider-time').slider('value',minhour);
							slideToggleFunction(minhour);
							playpause();
						}
						else{
							$('#slider-time').slider('value',val);
							slideToggleFunction(val);
						}
					}
				, slider_vel);

			}
			isPlaying = !isPlaying;
		}
		
		function centerLocation(init){
			if(navigator.geolocation) {
				if(userLocation==null){
					navigator.geolocation.watchPosition(function(position) {
						userLocation = new google.maps.LatLng(position.coords.latitude,
													   position.coords.longitude);
						var mark = new google.maps.Marker({
							map: map,
							position: userLocation,
							title: "User location",
							icon: 'img/user.png'
						});
						google.maps.event.addListener(mark, 'click', function(){
							clickFunction(userLocation);
						});
						if(init == false){
							map.setCenter(userLocation);
							clickFunction(userLocation);
						}
					},
					function (error) { 
					  if (error.code == error.PERMISSION_DENIED)
						document.getElementById("userLocation").style.display = "none";
					});
				}
				else{
					if(init == false){
						map.setCenter(userLocation);
						clickFunction(userLocation);
					}
				}
			  } else {
				document.getElementById("userLocation").style.display = "none";
			  }
		}
		
		$(function(){
			$("#fastslow").switchbutton({
				checkedLabel: 'FAST',
				uncheckedLabel: 'SLOW'
			})
			.change(function(){
				if($(this).prop("checked") == true){
					slider_vel = 400;
				}
				else{
					slider_vel = 1000;
				}
				playpause();
				playpause();
				//alert("Switch 6 changed to " + ($(this).prop("checked") ? "checked" : "unchecked"));
			});
			
			$("#timetype").switchbutton({
				checkedLabel: 'SLOT',
				uncheckedLabel: 'CONTINUOUS'
			})
			.change(function(){
				isTimeSlot = !isTimeSlot;
				if(currentShown == 0) toggleSamples();
				else if(currentShown == 1) toggleLight();
				else if(currentShown == 2) toggleNoise();
			});
			$("#videoimage").switchbutton({
				checkedLabel: 'EVOLUTION',
				uncheckedLabel: 'AVERAGE'
			})
			.change(function(){
				if(isPlaying){
					document.getElementById("img-playpause").src = "img/play.png";
					window.clearInterval(timeoutVar);
				}
				if($(this).prop("checked") == true){
					isVideoSelected = true;
					jQuery("#tablerow").show();
				}
				else{
					isVideoSelected = false;
					jQuery("#tablerow").hide();
				}
				if(currentShown == 0) toggleSamples();
				else if(currentShown == 1) toggleLight();
				else if(currentShown == 2) toggleNoise();
			});
			$("#videoimage").prop("checked", false).change();
		});
		
		function buildPanel(){
			jQuery("#toInsert").detach().appendTo('#samples');
		}
		
		google.maps.event.addDomListener(window, 'load', initialize);
    </script>
  </head>

  <body>
	<button onclick="removeMarker()" id="removeMarkerId" class="myButton" style="display:none"  title="Remove the marker from the clicked position"><font size=3>Remove marker</font><img src="img/marker.png" height="23px" alt="Remove Marker" style="padding-left: 5px; vertical-align:middle;"/></button>
	
	
	<div id="controlButtons">
		 <div><button onclick="fullscreen()" id="fullscreen" class="myButton"  title="Fullscreen toggle"><img id="image_fullscren" src="img/fullscreen_in.png" width="40" height="40" title="Fullscreen" /> </button></div>
		 <div><button onclick="centerLocation(false)" id="userLocation" class="myButton"  title="Center the map in the user location"><img src="img/centerlocation.png" width="40" height="40" title="User Location" /> </button></div>
	</div>
	
	<div class="wrap" id="bottomSlider">
		  <ul class="tabs group">
			<li><a class="active" href="#/samples">Samples</a></li>
			<li><a href="#/light">Light</a></li>
			<li><a href="#/noise">Noise</a></li>
		  </ul>
		  <div id="panels">
			<p id="samples"></p>
			<p id="light"></p>
			<p id="noise"></p>
		  </div>
	</div>
	
	<table width="100%" height="100%">
		<tr>
			<td width="50%" id="contentMap">
				<div id="map-canvas" ></div>
			</td>
			<td width="50%" id="contentGraphs" >
				<div id="infoRadius" class="labeltextbox" title="Set the marker radius">Radius: <input type="number" id="radiusM" max="30000" min="1" size="2" value="100" class="textbox"> m</div>
				<div class="scrollable">
					<center><b><div id="numSamples"></div></b></center>
					<button class="myButton" id="graphLightToggle" style="float: right;">Show/Hide Light Graph</button>
					<div id="graphLight"></div><br>
					<button class="myButton" id="graphNoiseToggle" style="float: right;">Show/Hide Noise Graph</button>
					<div id="graphNoise"></div>
				</div>
			</td>
		</tr>
	</table>
	
	<div id="toInsert">
		<center><font color="red"><div id="tabTitle">Number and location of samples</div></font></center>
		<table width="100%" height="100%" id="tablePanel" >
			<tr>
				<td>
					<center><div id="fromto">
						From: <input type="text"id="datepickerfrom" size=12 />
						To: <input type="text" id="datepickerto" size=12 />
					</div></center>
				</td>
				<td>
					<center><div id="timetypediv"  title="Show all samples made between the two dates/hours or just ones in the selected time slot"><input type="checkbox" id="timetype" style="position: relative;"/></div></center>
				</td>
			</tr>
			<tr  valign="middle">
				<td colspan="2">
					<center><input type="checkbox" id="videoimage" style="position: relative;"/> <font size=2>Shows the average value of the samples or the evolution during the day</font></center>
				</td>
			</tr>
			<tr id="tablerow">
				<td width="100%" colspan="3">
					<table width="100%" height="100%">
						<td>
							<center><input type="checkbox" id="fastslow" style="position: relative;" /></center>
						</td>
						<td width="5%">
							<div style="display:inline;">
								<button onclick="playpause()" id="playpause" class="myButton"  title="Play/Pause animation">
									<img id="img-playpause" src="img/play.png" width="20" height="20" />
								</button>
							</div>
						</td>
						<td width="75%">
							<center><div id="slider-time" style="margin-left: 5px; margin-right:5px;"></div>
							<font size=2.5>Time range: <label id="time"></label></font></center>
						</td>
						<td width="10%">
							<center>
								<font size=2>Step</font>
								<select id="timestep" class="styled-select blue semi-square">
								  <option value=15>15 min</option>
								  <option value=30>30 min</option>
								  <option value=60 selected="selected">60 min</option>
								</select>
							</center>
						</td>
					</table>
				</td>
			</tr>
		</table>
	</div>
	
	
  </body>
</html>
