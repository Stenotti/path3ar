<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
	<link rel="shortcut icon" type="image/x-icon" href="favicon.ico">
    <title>PathS</title>
	
    <link href="css/index.css" rel="stylesheet" type="text/css">
    <link href="css/atooltip.css" rel="stylesheet" type="text/css">
    <link href="css/ui.switchbutton.css" rel="stylesheet" type="text/css">
	<link rel="stylesheet" type="text/css" href="css/jquery.datetimepicker.css"/ >
	<link rel="stylesheet" href="css/jquery-ui.css">
	
	<script src="js/customFormat.js"></script>
	<script src="js/jquery.min.js"></script>
	<script src="js/jquery-ui.js"></script>
	<script type="text/javascript" src="js/jquery.tmpl.min.js"></script>
	<script src="js/jquery.switchbutton.js"></script>
	<script type="text/javascript" src="js/jquery.atooltip.min.js"></script>  
	<script src="js/highstock.js"></script>
	<script src="js/jquery.datetimepicker.js"></script>
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
				if(currentShown == 0) samplesDataTemp = samplesData;
				else if(currentShown == 1 || currentShown == 2) samplesDataTemp = samplesTimedDataGraph[currentArrayIndex];
				
				for (var i = 0; i < samplesDataTemp.length; i++) {
					var jsonCoord = samplesDataTemp[i];
					var radius = document.getElementById('radiusM').value;
					var dist = distanceInMeters(currentCoord.lat(), currentCoord.lng(), jsonCoord[1], jsonCoord[2]);
					if(dist <= radius){
						var ms=Date.parse(jsonCoord[3])-offsetMS;
						if(ms >= mindate && ms <= maxdate)
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
					
			getSamples();
			createTimedArrays();
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
					minhour = selectedtimestart * sliderStep * (60/sliderStep);
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
					maxhour = selectedtimeend * sliderStep * (60/sliderStep);
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
				  console.log($(this)+" - "+$(this).next());
				  return false;
			});
			$('#graphNoiseToggle').click(function() {
				  $('#graphNoise').slideToggle();
				  console.log($(this)+" - "+$(this).next());
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
			$('#fromto').aToolTip({  
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
			
			if(currentShown == 1 || currentShown == 2){
				updateDataInTimeRange();
				showGraph();
			}
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
			if(currentShown == 1 || currentShown == 2){
				heatmap.setData([]);
				setAllMap(null);
				currentArrayIndex = parseInt(hours)*(60/sliderStep) + parseInt(minutes/sliderStep);
				
				var lightDataTimeRange = [];
				var noiseDataTimeRange = [];
				for(var i=0; i<samplesIndexes[currentArrayIndex].length; i++){
					
					var jsonCoord = samplesData[samplesIndexes[currentArrayIndex][i]];
					var ms=Date.parse(jsonCoord[3])-offsetMS;
					if(ms >= mindate && ms <= maxdate){
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
			document.getElementById("bottomSlider").style.display = "none";
			//if(currentShown != 0){
				currentShown = 0;
				heatmap.setData([]);
				setAllMap(null);
				var samplesCoordsDataTimeRange = [];
				for (var i = 0; i < samplesData.length; i++) {
					var jsonCoord = samplesData[i]; 
					//[0]=id, [1]=lat, [2]=lng, [3]=timestamp, [4]=type1, [5]=value1, [6]=type2, [7]=value2
					var ms=Date.parse(jsonCoord[3])-offsetMS;
					if(ms >= mindate && ms <= maxdate){
						var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
						setMarkerMap(i,map);
						samplesCoordsDataTimeRange.push(coord);
					}
				}
				if(samplesCoordsDataTimeRange.length == 0)
					alert("There are no samples in this range");
				var pointArray = new google.maps.MVCArray(samplesCoordsDataTimeRange);
				heatmap.setData(pointArray);
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
			//}
		}
		
		function toggleLight() {
			$("#samplesButton").removeClass("buttonActive");
			$("#lightButton").addClass("buttonActive");
			$("#noiseButton").removeClass("buttonActive");
			//removeMarker();
			document.getElementById("bottomSlider").style.display = "block";
			//if(currentShown != 1){
				currentShown = 1;
				heatmap.setData([]);
				setAllMap(null);
				updateDataInTimeRange();
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
			//}
		}
		
		function toggleNoise() {
			$("#samplesButton").removeClass("buttonActive");
			$("#lightButton").removeClass("buttonActive");
			$("#noiseButton").addClass("buttonActive");
			//removeMarker();
			document.getElementById("bottomSlider").style.display = "block";
			//if(currentShown != 2){
				currentShown = 2;
				heatmap.setData([]);
				setAllMap(null);
				updateDataInTimeRange();
				if(currentCoord != null) showGraph();
				if(isPlaying) playpause();
			//}
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
						console.log("val="+val+", minhour="+minhour+", maxhour="+maxhour);
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
		});
		
		google.maps.event.addDomListener(window, 'load', initialize);

    </script>
  </head>

  <body>
	<button onclick="removeMarker()" id="removeMarkerId" class="myButton" style="display:none"  title="Remove the marker from the clicked position"><font size=3>Remove marker</font><img src="img/marker.png" height="23px" alt="Remove Marker" style="padding-left: 5px; vertical-align:middle;"/></button>
    
	<div id="fromto" class="labeltextbox" title="Set the dates range"><b>Show the samples in the range from:<input type="text"id="datepickerfrom" size=12>	to:<input type="text" id="datepickerto" size=12></b></div></div>
	
	<div id="bottomPanel">
      <button onclick="toggleSamples()" id="samplesButton" title="Location of samples" class="myButton"><font size=5>Samples</font></button><br>
      <button onclick="toggleLight()" id="lightButton" title="Light level, green means low light while red means high light" class="myButton"><font size=5>Light</font></button><br>
      <button onclick="toggleNoise()" id="noiseButton" title="Noise level, green means a quiet area while red means a noisy area" class="myButton"><font size=5>Noise</font></button><br>
	</div>
	
	<div id="bottomSlider" >
		<table width="100%" height="100%">
			<tr>
				<td width="30%">
					<center><input type="checkbox" id="fastslow" style="position: relative;" /></center>
				</td>
				<td width="40%">
					<center><b>Time range: <label id="time"></label></b></center>
				</td>
				<td width="30%">
					<center>
						<b>Time step</b>
						<select id="timestep" class="styled-select blue semi-square">
						  <option value=15>15 min</option>
						  <option value=30>30 min</option>
						  <option value=60 selected="selected">60 min</option>
						  <option value=120>120 min</option>
						  <option value=180>180 min</option>
						  <option value=1440>All interval</option>
						</select>
					</center>
				</td>
				
			</tr>
		</table>
		<table width="100%" height="100%">
			<tr>
				<td width="10%">
					<div id="sliderControls" style="display:inline;">
						<div style="display:inline;"><button onclick="playpause()" id="playpause" class="myButton"  title="Play/Pause animation"><img id="img-playpause" src="img/play.png" width="20" height="20" /> </button></div>
					</div>
				</td>
				<td width="90%">
					<table width="100%" height="100%">
						<td width="5%">
							<center><div id="timestart"><b>00:00</b></div></center>
						</td>
						<td width="90%">
							<center><div id="slider-time" style="margin-left: 5px; margin-right:5px;"></div></center>
						</td>
						<td width="5%">
							<center> <div id="timeend"><b>24:00</b></div></center>
						</td>
					</table>
				</td>
			</tr>
		</table>
	</div>
	<div id="controlButtons">
		 <div><button onclick="fullscreen()" id="fullscreen" class="myButton"  title="Fullscreen toggle"><img id="image_fullscren" src="img/fullscreen_in.png" width="40" height="40" title="Fullscreen" /> </button></div>
		 <div><button onclick="centerLocation(false)" id="userLocation" class="myButton"  title="Center the map in the user location"><img src="img/centerlocation.png" width="40" height="40" title="User Location" /> </button></div>
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
	

	
	<!-- <div id="wait" style="display:none;width:128px;height:128px;border:0px; position:absolute;top:40%;left:45%;padding:2px;">
		<img src='img/loader.gif' width="100" height="100" /><br>
		<font color="#fff">Caricamento dati</font>
	</div> -->
  </body>
</html>