/* =====================================================================
   API docs: fill in live token + endpoint + PHP example
   ===================================================================== */
(function () {
    'use strict';
    const { api, copyText } = window.KP;
    const page = document.body.dataset.page;
    if (page !== 'api') return;

    const tokenLine = document.getElementById('apiDocTokenLine');
    const baseLine  = document.getElementById('apiDocBaseLine');
    const phpBlock  = document.getElementById('apiPhpExample');

    async function fill() {
        let endpoint = '';
        let token = '';
        try {
            const r = await api('api/api-docs-sample.php');
            endpoint = r.endpoint; token = r.token;
        } catch (e) {}

        if (baseLine) baseLine.textContent = endpoint;
        if (tokenLine) tokenLine.textContent = 'api_key = ' + token;

        if (phpBlock) {
            phpBlock.textContent = `<?php
$data = [
    'action'     => 'buy',
    'variant_id' => 201,  // from "My API" page → Your Variants
    'quantity'   => 1,    // optional, default 1, max 10
];

$headers = [
    'Content-Type: application/x-www-form-urlencoded',
    'x-master-key: ${token}'
];

$ch = curl_init('${endpoint}');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($data),
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 15
]);
$response = curl_exec($ch);
if (curl_errno($ch)) { echo "cURL error: " . curl_error($ch); curl_close($ch); exit; }
curl_close($ch);

$result = json_decode($response, true);
if ($result === null) { echo "Invalid response from server"; }
elseif ($result['ok']) {
    echo "Key: " . $result['key'];
    echo " | Order: " . $result['order_id'];
    echo " | Expires: " . $result['expires_at'];
} else {
    echo "Error: " . $result['error'];
}`;
        }
    }

    document.querySelectorAll('.api-doc-copy').forEach(btn => {
        btn.addEventListener('click', async () => {
            const target = document.getElementById(btn.dataset.copyTarget);
            if (!target) return;
            try { await copyText(target.textContent || ''); btn.textContent = '✓ Copied'; setTimeout(() => btn.textContent = '📋 Copy', 1200); } catch (e) {}
        });
    });

    fill();
})();