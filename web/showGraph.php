<?php
if(isset($_POST['ids']) && !empty($_POST['ids'])){
	$ids = json_decode($_POST['ids']);
	$conn_string = "host=pathdb.ccokugosuzr8.eu-west-1.rds.amazonaws.com port=5432 dbname=pathdb user=pathadmin password=pathdbpwd";
	$dbconn = pg_connect($conn_string) or die('connection failed');
	
	echo "asd";

	/*$response = array();
	
	foreach ($ids as $id) {
		$result = pg_exec($dbconn, "select label.type, label.value, sample.timestamp from label, sample where label.sample_id=sample.id and label.sample_id=$id");
		$numrows = pg_numrows($result);
		if($numrows == 2){
			for($ri = 0; $ri < $numrows; $ri++) {
				$row = pg_fetch_row($result, $ri);
				$response[] = $row;
			}
		}
	}
	// save the JSON encoded array
	$jsonData = json_encode($response); 
	echo $jsonData;*/
	pg_close($dbconn);
}
else{
	return die('missing parameter');
}

?>