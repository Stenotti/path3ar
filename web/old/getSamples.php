<?php

$conn_string = "host=pathdb.ccokugosuzr8.eu-west-1.rds.amazonaws.com port=5432 dbname=pathdb user=pathadmin password=pathdbpwd";
$dbconn = pg_connect($conn_string) or die('connection failed');

$result = pg_exec($dbconn, "select id, latitude, longitude from sample");
$numrows = pg_numrows($result);

$response = array();
for($ri = 0; $ri < $numrows; $ri++) {
    $row = pg_fetch_row($result, $ri);
	$response[] = $row;
}

$jsonData = json_encode($response); 
echo $jsonData;
pg_close($dbconn);
?>