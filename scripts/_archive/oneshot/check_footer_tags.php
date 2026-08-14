<?php
$home = file_get_contents(dirname(__DIR__).'/public/888/partials/tab-home.php');
preg_match_all('/data-copy="footer_line[123]"[^>]*>.*$/mu', $home, $m);
foreach ($m[0] as $line) {
  echo bin2hex($line)."\n";
  echo $line."\n\n";
}
// any broken closers
if (preg_match_all('/[^<]\?<\/[a-z]+>/u', $home, $mm)) {
  echo "broken_closers=".count($mm[0])."\n";
  foreach ($mm[0] as $x) echo "  ".$x."\n";
}
