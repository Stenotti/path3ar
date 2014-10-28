<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
	<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <title>Heatmaps</title>
	
    <link href="css/index.css" rel="stylesheet" type="text/css">
    <link href="css/ui.switchbutton.css" rel="stylesheet" type="text/css">
	<link rel="stylesheet" href="//code.jquery.com/ui/1.11.1/themes/smoothness/jquery-ui.css">
	
	<script src="js/customFormat.js"></script>
	<script src="js/jquery.min.js"></script>
	<script src="js/jquery-ui.js"></script>
	<script type="text/javascript" src="js/jquery.tmpl.min.js"></script>
	<script src="js/jquery.switchbutton.js"></script>
	<script src="js/highstock.js"></script>
    <script src="https://maps.googleapis.com/maps/api/js?v=3.exp&libraries=visualization"></script>
	<script>
		var sliderStep = 60;
		var numberOfTimeRanges = 1440 / sliderStep;
		var samplesData; // Lista di tutti i campionamenti
		var samplesCoordsData = []; // Lista delle coordinate dei campionamenti per la mappa
		var samplesIndexes = [numberOfTimeRanges]; // Lista degli indici utilizzata per mostrare i campionamenti divisi per l'orario
		
		var samplesNoiseTimedData = [numberOfTimeRanges]; // Lista dei valori di rumorosità dei campionamenti divisi per l'orario (ogni quarto d'ora) -> per la mappa
		var samplesTimedDataGraph = [numberOfTimeRanges]; // Lista dei valori di rumorosità dei campionamenti divisi per l'orario (ogni quarto d'ora) -> per il grafico
		
		var samplesLightTimedData = [numberOfTimeRanges]; // Lista dei valori di lumonosità dei campionamenti divisi per l'orario (ogni quarto d'ora -> 24*4) -> per la mappa
		//var samplesLightTimedDataGraph = [numberOfTimeRanges]; // Lista dei valori di lumonosità dei campionamenti divisi per l'orario (ogni quarto d'ora -> 24*4) -> per il grafico
		
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
		
		var lightGraph, noiseGraph;
		
		var userLocation;
		
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
						var hourtime = date.customFormat("#DD#/#MM#/#YYYY# #hhh#:#mm#:#ss#");
						var name = "LIGHT: "+light+", NOISE: "+noise+" "+hourtime;
						var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
						samplesCoordsData.push(coord);
						
						bounds.extend(coord);
						addInvisibleMarker(map, name, coord);
					}
					toggleSamples(); // di default mostro i campionamenti
					//$('#wait').hide();
				}
			}
			//$('#wait').show();
			xmlhttp.open("GET","getSamples.php",false);
			xmlhttp.send();
		}
		
		function addInvisibleMarker(map, name, latlng){
			var image = new google.maps.MarkerImage(document.URL+'img/invisible-marker.png',
				null, 
				null,
				new google.maps.Point(25, 25)
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
				if(currentShown == 0) samplesDataTemp = samplesData;
				else if(currentShown == 1 || currentShown == 2) samplesDataTemp = samplesTimedDataGraph[currentArrayIndex];
				
				for (var i = 0; i < samplesDataTemp.length; i++) {
					var jsonCoord = samplesDataTemp[i];
					var radius = document.getElementById('radiusM').value;
					var dist = distanceInMeters(currentCoord.lat(), currentCoord.lng(), jsonCoord[1], jsonCoord[2]);
					if(dist <= radius){
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
							dashStyle: 'dash'/*,
							label: {
								text: datetext,
								align: 'center',
								y: 12,
								x: 0
							}*/
						});
						noiseGraph.xAxis[0].addPlotLine({
							id: 'noise-plotLine',
							value: plotXLines[i],
							width: 1,
							color: 'green',
							dashStyle: 'dash'/*,
							label: {
								text: datetext,
								align: 'center',
								y: 12,
								x: 0
							}*/
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

		function initialize() {
			bounds = new google.maps.LatLngBounds();
			var mapOptions = {
				mapTypeId: google.maps.MapTypeId.SATELLITE
			};
			map = new google.maps.Map(document.getElementById('map-canvas'), mapOptions);
			heatmap = new google.maps.visualization.HeatmapLayer();
			heatmap.setMap(map);
			heatmap.set('maxIntensity', 8);
			
			var date = new Date();
			offsetMS = date.getTimezoneOffset()*60*1000;
					
			getSamples();
			createTimedArrays();
			map.fitBounds(bounds);
			
			$('#time').html("00:00 - 00:"+(sliderStep-1));
			$('#numSamples').html("Number of samples: 0");
			
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
				  console.log($(this)+" - "+$(this).next());
				  return false;
			});
			$('#graphNoiseToggle').click(function() {
				  $('#graphNoise').slideToggle();
				  console.log($(this)+" - "+$(this).next());
				  return false;
			});
			centerLocation();
		}
		
		function clickFunction(coord){
			currentCoord = coord;
			var lat = coord.lat();
			var lng = coord.lng();
			map.panTo(coord);
			var zoom = map.getZoom();
			if(zoom < 13)
				zoom = 13;
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
			  radius: parseFloat(document.getElementById('radiusM').value)
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
			$('#time').html(hours+':'+minutes+" - "+hours+':'+(parseInt(minutes)+sliderStep-1));
			
			if(currentShown == 1 || currentShown == 2){
				updateDataInTimeRange();
				showGraph();
			}
		}
		
		jQuery(function() {
			jQuery('#slider-time').slider({
				range: false,
				min: 0,
				max: 1440-sliderStep,
				animate: "slow",
				step: sliderStep,
				slide: function(e, ui) {
					slideToggleFunction(ui.value);
				}
			});
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
		
		function createTimedArrays(){
			for(var i=0; i<numberOfTimeRanges; i++) {
				samplesLightTimedData[i] = [];
				samplesTimedDataGraph[i] = [];
				samplesNoiseTimedData[i] = [];
				//samplesNoiseTimedDataGraph[i] = [];
				samplesIndexes[i] = [];
			}
			for(var i=0; i<samplesData.length; i++){
				var jsonCoord = samplesData[i];
				var ms=Date.parse(jsonCoord[3])-offsetMS;
				var date = new Date(ms);
				var hourtime = date.customFormat("#hhh#:#mm#:#ss#");
				var sampleHour = hourtime.split(":")[0];
				var sampleMins = hourtime.split(":")[1];
				var arrayIndex = sampleHour*(60/sliderStep) + parseInt(sampleMins/sliderStep);
				
				var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
				var light = getLightFromJson(jsonCoord);
				var noise = getNoiseFromJson(jsonCoord);
				
				samplesTimedDataGraph[arrayIndex].push(jsonCoord);
				
				var checkAdd = true;
				for(var j=0; j<samplesIndexes[arrayIndex].length; j++){
					var toCheck = samplesIndexes[arrayIndex][j];
					var d = distanceInMeters(samplesCoordsData[i].lat(),samplesCoordsData[i].lng(),
											 samplesCoordsData[toCheck].lat(),samplesCoordsData[toCheck].lng());
					if(d<10){
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
			
		}
		
		function updateDataInTimeRange(){
			if(currentShown == 1 || currentShown == 2){
				heatmap.setData([]);
				setAllMap(null);
				currentArrayIndex = parseInt(hours)*(60/sliderStep) + parseInt(minutes/sliderStep);
				for(var i=0; i<samplesIndexes[currentArrayIndex].length; i++){
					setMarkerMap(samplesIndexes[currentArrayIndex][i], map);
				}
				var dataToSet;
				if(currentShown == 1){
					dataToSet = new google.maps.MVCArray(samplesLightTimedData[currentArrayIndex]);
				}
				else if(currentShown == 2){
					dataToSet = new google.maps.MVCArray(samplesNoiseTimedData[currentArrayIndex]);
				}
				heatmap.setData(dataToSet);
			}
		}
		
		function toggleSamples() {
			$("#samplesButton").addClass("buttonActive");
			$("#lightButton").removeClass("buttonActive");
			$("#noiseButton").removeClass("buttonActive");
			document.getElementById("bottomSlider").style.display = "none";
			if(currentShown != 0){
				currentShown = 0;
				heatmap.setData([]);
				setAllMap(map);
				var pointArray = new google.maps.MVCArray(samplesCoordsData);
				heatmap.setData(pointArray);
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
			}
		}
		
		function toggleLight() {
			$("#samplesButton").removeClass("buttonActive");
			$("#lightButton").addClass("buttonActive");
			$("#noiseButton").removeClass("buttonActive");
			//removeMarker();
			document.getElementById("bottomSlider").style.display = "block";
			if(currentShown != 1){
				currentShown = 1;
				heatmap.setData([]);
				setAllMap(null);
				updateDataInTimeRange();
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
			}
		}
		
		function toggleNoise() {
			$("#samplesButton").removeClass("buttonActive");
			$("#lightButton").removeClass("buttonActive");
			$("#noiseButton").addClass("buttonActive");
			//removeMarker();
			document.getElementById("bottomSlider").style.display = "block";
			if(currentShown != 2){
				currentShown = 2;
				heatmap.setData([]);
				setAllMap(null);
				updateDataInTimeRange();
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
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
				document.getElementById("panel").style.right = "51%";
				document.getElementById("bottomSlider").style.right = "55%";
			}
			else{
				document.getElementById("contentGraphs").style.display = "none";
				document.getElementById("contentMap").width = "100%";
				document.getElementById("controlButtons").style.right = "2%";
				document.getElementById("panel").style.right = "2%";
				document.getElementById("bottomSlider").style.right = "13%";
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
						if(val>=1440){
							$('#slider-time').slider('value',0);
							slideToggleFunction(0);
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
		
		function centerLocation(){
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
						map.setCenter(userLocation);
					},
					function (error) { 
					  if (error.code == error.PERMISSION_DENIED)
						document.getElementById("userLocation").style.display = "none";
					});
				}
				else{
					map.setCenter(userLocation);
				}
			  } else {
				document.getElementById("userLocation").style.display = "none";
			  }
		
			if(navigator.geolocation) {
				navigator.geolocation.watchPosition(function(position) {
				  console.log("i'm tracking you!");
				},
				function (error) { 
				  if (error.code == error.PERMISSION_DENIED)
					  console.log("you denied me :-(");
				});
				navigator.geolocation.getCurrentPosition(function(position) {
					userLocation = new google.maps.LatLng(position.coords.latitude,
												   position.coords.longitude);
					var mark = new google.maps.Marker({
						map: map,
						position: userLocation,
						title: "User location",
						icon: 'img/user.png'
					});
					map.setCenter(userLocation);
				}, function() {
				});
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
		});
		
		google.maps.event.addDomListener(window, 'load', initialize);

    </script>
  </head>

  <body>
    <div id="panel" class="labeltextbox">
	  Radius: <input type="number" id="radiusM" max="30000" min="1" size="2" value="100" class="textbox"> m<br><br>
      <button onclick="removeMarker()" id="removeMarkerId" class="myButton" style="display:none">Remove marker</button>
    </div>
	<div id="bottomPanel">
      <button onclick="toggleSamples()" id="samplesButton" class="myButton"><font size=5>Samples</font></button><br>
      <button onclick="toggleLight()" id="lightButton" class="myButton"><font size=5>Light</font></button><br>
      <button onclick="toggleNoise()" id="noiseButton" class="myButton"><font size=5>Noise</font></button><br>
	</div>
	
	<div id="bottomSlider" >
		<table width="100%" height="100%">
			<tr>
				<td width="25%">
					<center><input type="checkbox" id="fastslow" style="position: relative;" /></center>
				</td>
				<td width="75%">
					<center><b>Time range: <label id="time"></label></b></center>
				</td>
			</tr>
		</table>
		<table width="100%" height="100%">
			<tr>
				<td width="10%">
					<div id="sliderControls" style="display:inline;">
						<div style="display:inline;"><button onclick="playpause()" id="playpause" class="myButton"><img id="img-playpause" src="img/play.png" width="20" height="20" title="Play" /> </button></div>
					</div>
				</td>
				<td width="90%">
					<div id="slider-time"></div>
				</td>
			</tr>
		</table>
	</div>
	<div id="controlButtons">
		 <div><button onclick="fullscreen()" id="fullscreen" class="myButton"><img src="img/fullscreen.png" width="40" height="40" title="Fullscreen" /> </button></div>
		 <div><button onclick="centerLocation()" id="userLocation" class="myButton"><img src="img/centerlocation.png" width="40" height="40" title="User Location" /> </button></div>
	</div>
	
	<table width="100%" height="100%">
		<tr>
			<td width="50%" id="contentMap">
				<div id="map-canvas" ></div>
			</td>
			<td width="50%" id="contentGraphs" >
				<div class="scrollable">
					<center><b><div id="numSamples"></div></b></center>
					<button class="myButton" id="graphLightToggle">Show/Hide Light Graph</button>
					<div id="graphLight"></div><br>
					<button class="myButton" id="graphNoiseToggle">Show/Hide Noise Graph</button>
					<div id="graphNoise"></div>
				</div>
			</td>
		</tr>
	</table>
	
	<!-- <div id="wait" style="display:none;width:128px;height:128px;border:0px; position:absolute;top:40%;left:45%;padding:2px;">
		<img src='img/loader.gif' width="100" height="100" /><br>
		<font color="#fff">Caricamento dati</font>
	</div> -->
  </body>
</html>