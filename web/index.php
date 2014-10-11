<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <title>Heatmaps</title>
    <style>
      html, body, #map-canvas {
        height: 100%;
        margin: 0px;
        padding: 0px
      }
      #panel {
        position: absolute;
        top: 5px;
        left: 25%;
        z-index: 5;
        background-color: #fff;
        padding: 5px;
        border: 1px solid #999;
      }
    </style>
	<link rel="stylesheet" href="//code.jquery.com/ui/1.11.1/themes/smoothness/jquery-ui.css">
	<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.8.2/jquery.min.js"></script>
	<script src="http://code.highcharts.com/stock/highstock.js"></script>
    <script src="https://maps.googleapis.com/maps/api/js?v=3.exp&libraries=visualization"></script>
	<script>
		var samplesData = [];
		var graphDataIds = [];
		var map, heatmap;
		var bounds;
		var zoomFluid;
		var jsonData;
		var currentCoord;
		var marker, markerCircle;
		
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
					jsonData = JSON.parse(xmlhttp.responseText);
					for (var i = 0; i < jsonData.length; i++) {
						var jsonCoord = jsonData[i];
						var coord = new google.maps.LatLng(jsonCoord[1], jsonCoord[2]);
						samplesData.push(coord);
						bounds.extend(coord);
					}
				}
			}
			xmlhttp.open("GET","getSamples.php",false);
			xmlhttp.send();
		}
		
		function distance(lat1, lon1, lat2, lon2){
			var R = 6371;
			var a = 0.5 - Math.cos((lat2 - lat1) * Math.PI / 180)/2 + Math.cos(lat1 * Math.PI / 180) * 
					Math.cos(lat2 * Math.PI / 180) * (1 - Math.cos((lon2 - lon1) * Math.PI / 180))/2;
			return R * 2 * Math.asin(Math.sqrt(a));
		}
				
		function showGraph() {
			graphDataIds = [];
			var jsonObj = [];
			for (var i = 0; i < jsonData.length; i++) {
				var jsonCoord = jsonData[i];
				var radius = document.getElementById('radiusKM').value;
				var dist = distance(currentCoord.lat(), currentCoord.lng(), jsonCoord[1], jsonCoord[2]);
				if(dist <= radius){
					graphDataIds.push(jsonCoord[0]); // Inserisco l'id del sample
				}
			}
		
			if (window.XMLHttpRequest) {
				// code for IE7+, Firefox, Chrome, Opera, Safari
				xmlhttp=new XMLHttpRequest();
			} else { // code for IE6, IE5
				xmlhttp=new ActiveXObject("Microsoft.XMLHTTP");
			}
			xmlhttp.onreadystatechange=function() {
				if (xmlhttp.readyState==4 && xmlhttp.status==200) {
					var labelData = JSON.parse(xmlhttp.responseText);
					var lightData = [];
					var noiseData = [];
					for (var i = 0; i < labelData.length; i++) {
						var label = labelData[i];
						var ms=Date.parse(label[2]);
						var value = parseFloat(label[1]);
						if(value<0 || value == 0.0001) 
							value = 0.0;
						if(value == 100.0001)
							value = 100.0;
						var toAdd = [];
						toAdd.push(ms);
						toAdd.push(value);
						if(label[0] == "LIGHT")
							lightData.push(toAdd);
						else
							noiseData.push(toAdd);
					}
					lightData = lightData.sort(Comparator);
					noiseData = noiseData.sort(Comparator);
										
					Highcharts.setOptions({
						global : {
							useUTC : false
						}
					});
					$('#graph').highcharts('StockChart', {
						rangeSelector: {
							selected: 5,
							allButtonsEnabled: true
						},
						chart: {
							zoomType: 'xy'
						},
						title: {
							text: 'LIGHT/NOISE'
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
							name: 'LIGHT',
							data: lightData
						},{
							name: 'NOISE',
							data: noiseData
						}]
					});
					
					$('#wait').hide();
					if(lightData.length == 0 && noiseData.length == 0){
						alert("Non ci sono campionamenti in questo raggio");
					}
					else{
						var dest = 0;
						if ($('#graph').offset().top > $(document).height() - $(window).height()) {
							dest = $(document).height() - $(window).height();
						} else {
							dest = $('#graph').offset().top;
						}
						//go to destination
						$('html,body').animate({
							scrollTop: dest
						}, 2000, 'swing');
					}
				}
			}
			xmlhttp.open("POST","showGraph.php",true);
			xmlhttp.setRequestHeader("Content-type","application/x-www-form-urlencoded");
			xmlhttp.send("ids="+JSON.stringify(graphDataIds));
			$('#wait').show();
		}

		function initialize() {
			bounds = new google.maps.LatLngBounds();
			getSamples();
			var mapOptions = {
				//zoom: 13,
				//center: new google.maps.LatLng(45.387590, 11.895832),
				mapTypeId: google.maps.MapTypeId.SATELLITE
			};
			map = new google.maps.Map(document.getElementById('map-canvas'), mapOptions);
			var pointArray = new google.maps.MVCArray(samplesData);

			heatmap = new google.maps.visualization.HeatmapLayer({
				data: pointArray
			});
			heatmap.setMap(map);
			map.fitBounds(bounds);
			
			google.maps.event.addListener(map, "click", function(event) {
				currentCoord = event.latLng;
				var lat = event.latLng.lat();
				var lng = event.latLng.lng();
				map.panTo(currentCoord);
				var zoom = map.getZoom();
				if(zoom < 15)
					zoom = 15;
				setTimeout("map.setZoom("+zoom+")",500);
				if(marker != null){
					marker.setMap(null);
					markerCircle.setMap(null);
				}
				marker = new google.maps.Marker({
					position: currentCoord,
					map: map,
					title: currentCoord
				});
				var circleOptions = {
				  strokeColor: '#FF0000',
				  strokeOpacity: 0.8,
				  strokeWeight: 2,
				  fillColor: '#FF0000',
				  fillOpacity: 0.35,
				  map: map,
				  center: currentCoord,
				  radius: document.getElementById('radiusKM').value
				};
				// Add the circle for this city to the map.
				markerCircle = new google.maps.Circle(circleOptions);
				showGraph();
			});
		}
		

		function toggleHeatmap() {
			heatmap.setMap(heatmap.getMap() ? null : map);
		}

		function changeGradient() {
		  var gradient = [
			'rgba(0, 255, 255, 0)',
			'rgba(0, 255, 255, 1)',
			'rgba(0, 191, 255, 1)',
			'rgba(0, 127, 255, 1)',
			'rgba(0, 63, 255, 1)',
			'rgba(0, 0, 255, 1)',
			'rgba(0, 0, 223, 1)',
			'rgba(0, 0, 191, 1)',
			'rgba(0, 0, 159, 1)',
			'rgba(0, 0, 127, 1)',
			'rgba(63, 0, 91, 1)',
			'rgba(127, 0, 63, 1)',
			'rgba(191, 0, 31, 1)',
			'rgba(255, 0, 0, 1)'
		  ]
		  heatmap.set('gradient', heatmap.get('gradient') ? null : gradient);
		}

		function changeRadius() {
		  heatmap.set('radius', heatmap.get('radius') ? null : 20);
		}

		function changeOpacity() {
		  heatmap.set('opacity', heatmap.get('opacity') ? null : 0.2);
		}

		google.maps.event.addDomListener(window, 'load', initialize);

    </script>
  </head>

  <body>
    <div id="panel">
	  Radius: <input type="number" id="radiusKM" max="30" min="1" size="3" value="1">km
      <button onclick="toggleHeatmap()">Toggle Heatmap</button>
      <button onclick="changeGradient()">Change gradient</button>
      <button onclick="changeRadius()">Change radius</button>
      <button onclick="changeOpacity()">Change opacity</button>
    </div>
    <div id="map-canvas" ></div>
	<div id="graph" style="width:100%; height:400px;"></div>
	<div id="wait" style="display:none;width:128px;height:128px;border:0px; position:absolute;top:40%;left:45%;padding:2px;">
		<img src='loader.gif' width="100" height="100" /><br>
		<font color="#fff">Caricamento dati</font>
	</div>
  </body>
</html>