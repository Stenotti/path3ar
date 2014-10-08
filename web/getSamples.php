<?php

$conn_string = "host=pathdb.ccokugosuzr8.eu-west-1.rds.amazonaws.com port=5432 dbname=pathdb user=pathadmin password=pathdbpwd";
$dbconn = pg_connect($conn_string) or die('connection failed');

/*mysqli_select_db($con,"ajax_demo");
$sql="SELECT * FROM sample";
$result = mysqli_query($con,$sql);

echo "<table border='1'>
<tr>
<th>ID</th>
<th>Latitude</th>
<th>Longitude</th>ì
</tr>";

while($row = mysqli_fetch_array($result)) {
  echo "<tr>";
  echo "<td>" . $row['id'] . "</td>";
  echo "<td>" . $row['latitude'] . "</td>";
  echo "<td>" . $row['longitude'] . "</td>";
  echo "</tr>";
}
echo "</table>";

mysqli_close($con);*/
?>