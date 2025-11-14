    <?php

    ini_set('display_errors', 1);
    ini_set('display_startup_errors',1);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none';");

    require_once $_SERVER['DOCUMENT_ROOT'] . '/andlineSE/wp-load.php';

    require_once dirname(__DIR__, 1) . '/common/index.php';

    use common\utils as commonUtils;
    use common\errors\ErrorCodes;
    use common\env as env;
    use common\errors\ApiException;

    $envList = env\envList();

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }


    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        commonUtils\ResponseHelper::respondWithError(ErrorCodes::INVALID_METHOD, "Invalid request method", 405);
        exit;
    }



    //if (!isset($_POST['csrf_token']) || !wp_verify_nonce($_POST['csrf_token'], '10g-questionnaire_nonce')) {
        //commonUtils\ResponseHelper::respondWithError(ErrorCodes::CSRF_INVALID, "CSRFトークンが無効です。", 403);
    // exit;
    //}


    //Honeypotチェック////////////////////////////////////////////////////////////////////////////////////////////////////

    $honeypot = $_POST['g_response'] ?? '';//空//

    if (!empty($honeypot)) {
        commonUtils\ResponseHelper::respondWithError(ErrorCodes::HONEYPOT_TRIGGERED,'値あり（エラー）',400);
        exit;
    }
    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

    //入力値検証//////////////////////////////////////////////////////////////////////////////////////////////////////////

    $inputErrors = [];//エラー保存//

    $connected_devices = ['1', '3', '5+'];
    $try_service = ['オンラインゲーム', '動画配信', 'オンライン授業・会議', 'ショッピング', '動画視聴', 'チケット争奪戦', 'オークション', 'トレード・株式'];
    $personality_type = ['待てない', 'よくばり', 'パワーッ'];
    $result_id = ['result01', 'result02', 'result03'];

    // Quest1: インターネットに繋いでいる機器は何台？
    if (empty($_POST['devices']) || !in_array($_POST['devices'], $connected_devices, true)) {
        $inputErrors[] = "Quest1回答エラー";
    }

    // Quest2: スピードアップしたら試してみたいサービスは
    if (empty($_POST['service']) || !is_array($_POST['service'])) {
        $inputErrors[] = "Quest2回答エラー";
    } else {
        foreach ($_POST['service'] as $service) {
            if (!in_array($service, $try_service, true)) {
                $inputErrors[] = "Quest2に不正な値が含まれています。";
                break;
            }
        }
    }

    // Quest3: あなたはどれ？
    if (empty($_POST['type']) || !in_array($_POST['type'], $personality_type, true)) {
        $inputErrors[] = "Quest3回答エラー";
    }

    // 結果
    if (empty($_POST['result_id']) || !in_array($_POST['result_id'], $result_id, true)) {
        $inputErrors[] = "結果IDエラー";
    }

    // エラーチェック
    if (!empty($inputErrors)) {commonUtils\ResponseHelper::respondWithError(ErrorCodes::VALIDATION_FAILED,implode(' ', $inputErrors),422);
        exit;
    }



    //結果表示
    $result_map = [
        'result01' => '戦士',
        'result02' => 'まほう使い',
        'result03' => 'きんにく',
    ];
    $result_text = $result_map[$_POST['result_id']];

    //////////////////////////////////////////////////////////////////////////////////////////////////////////////////////


    //Kintone API 実行////////////////////////////////////////////////////////////////////////////////////////////////////

    $kintone_app_id = $_ENV['KINTONE_APP_SE_10G_QUESTIONNAIRE_TEST'];
    $kintone_token  = $_ENV['KINTONE_TOKEN_SE_10G_QUESTIONNAIRE_TEST'];


    $record = [
        'DEVICES' => ['value' => $_POST['devices']],
        'SERVICE' => ['value' => implode(', ', $_POST['service'])],
        'TYPE'    => ['value' => $_POST['type']],
        'RESULT'  => ['value' => $result_text],
    ];

    $data = [
        "app" => $_ENV['KINTONE_APP_SE_10G_QUESTIONNAIRE_TEST'],//アプリ指定
        "record" => $record //データ指定
    ];

    $json = json_encode($data);

    try {
        $url = "https://icube-marketing.cybozu.com/k/v1/record.json"; //レコード追加するエンドポイント

        $headers = [
            "X-Cybozu-API-Token: {$kintone_token}", //APIトークン検証
            "Content-Type: application/json", //JSON形式で指定
        ];

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json); 
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);                                                         //失敗
        curl_close($curl);
        if (!($httpCode >= 200 && $httpCode < 300)) {
            throw new errors\ApiException("データの送信に失敗しました。", $httpCode, errors\ErrorCodes::KINTONE_ERROR);
        }
        $responsedata = json_decode($response, true);

        echo json_encode([
            "status" => "success",             //成功メッセージ
            "kintoneResponse" => $responsedata,
        ]);

    } catch (Throwable $e) {
        commonUtils\ResponseHelper::respondWithError(
            errors\ErrorCodes::KINTONE_ERROR,          //何か起きたらJSONエラー
            $e->getMessage(),
            $e->getCode() ?: 500
        );
        exit;
    }
    ///////////////////////////////////////////////////////////////////////////////////////////////////

