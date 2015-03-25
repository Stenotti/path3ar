<?php

$conn_string = "host=ec2-54-93-55-219.eu-central-1.compute.amazonaws.com port=5432 dbname=paths user=pathadmin password=pathdbpwd";
$dbconn = pg_connect($conn_string) or die('connection failed');

$result = pg_exec($dbconn, 
"select s.id, s.latitude, s.longitude, s.timestamp, l1.type, l1.value, l2.type, l2.value
from sample s, label l1, label l2
where l1.sample_id=s.id and l2.sample_id=s.id and l1.type <> l2.type
order by s.id");

$numrows = pg_numrows($result);

$response = array();
for($ri = 0; $ri < $numrows; $ri=$ri+2) {
    $row = pg_fetch_row($result, $ri);
	$response[] = $row;
}

$jsonData = json_encode($response); 
echo $jsonData;
pg_close($dbconn);
?>
