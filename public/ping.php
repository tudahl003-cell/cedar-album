<?php
// Lightweight liveness probe for the picker. NO anti-bot gating — a running
// service answers 200 here even to a bare request; a stopped one 502s via the
// Railway proxy. The picker uses this to drop retired/flagged members.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo "ok";
