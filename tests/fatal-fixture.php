<?php
// Isolated process: exercise an actual fatal error and the shutdown JSON response.
define('ABSPATH', __DIR__);
function wp_send_json_error($data, $status = 200) {
 if ($status !== 500 || strpos($data['message'], '#99') === false || strpos($data['message'], 'encoder-stage') === false) {
  fwrite(STDERR, "Fatal diagnostic assertion failed\n"); exit(1);
 }
 echo "PASS: actual PHP fatal returns attachment and stage via shutdown handler\n";
 exit(0);
}
require __DIR__ . '/../pixora/class-pixora-diagnostics.php';
Pixora_Diagnostics::begin(99, 'optimize');
Pixora_Diagnostics::stage('encoder-stage');
pixora_deliberately_missing_encoder();
