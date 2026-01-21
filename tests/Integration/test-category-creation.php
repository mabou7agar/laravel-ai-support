<?php

/**
 * Simple test to verify category creation works with the fixed workflow
 */

require_once __DIR__ . '/run-workflow-tests.php';

// Configuration
$BASE_URL = 'https://dash.test/ai-demo/chat/send';
$AUTH_TOKEN = '4|ZEzh7i9uepyJJ5C6JoXby6rMUhtzDwk2yXRQP3q71f5d2873';
$CSRF_TOKEN = '0Z8NElFh6KFEraL4FT1SNaa5mgOfKUpeDHfPVaXW';

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Testing Category Creation with Multiple Products\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$sessionId = 'test-cat-' . uniqid();

echo "Step 1: Starting invoice creation with new products...\n";
$response = sendRequest($BASE_URL, [
    'message' => 'create invoice for Mohamed Abou Hagar with 5 Macbook Pro Maxc and 2 iPhone Pro Max',
    'session_id' => $sessionId,
    'memory' => true,
    'actions' => true,
], $AUTH_TOKEN, $CSRF_TOKEN);

echo "Response: " . substr($response['body']['response'] ?? '', 0, 200) . "...\n\n";

$step = 1;
$maxSteps = 50;
$askedForMacbookPrice = false;
$askedForIPhonePrice = false;
$askedForCategory = false;
$workflowCompleted = false;

while ($step < $maxSteps) {
    $responseText = $response['body']['response'] ?? '';
    $lowerResponse = strtolower($responseText);
    
    echo "Step {$step}: ";
    
    // Check what the AI is asking for (order matters - check category before email!)
    if (str_contains($lowerResponse, 'don\'t exist') || str_contains($lowerResponse, 'would you like to create')) {
        echo "AI asking to create products\n";
        $message = 'yes';
    } elseif (str_contains($lowerResponse, 'category')) {
        echo "AI asking for category\n";
        $askedForCategory = true;
        $message = 'Electronics';
    } elseif (str_contains($lowerResponse, 'price') || str_contains($lowerResponse, 'sale price')) {
        if (str_contains($lowerResponse, 'macbook')) {
            echo "AI asking for Macbook price\n";
            $askedForMacbookPrice = true;
            $message = '2500';
        } elseif (str_contains($lowerResponse, 'iphone')) {
            echo "AI asking for iPhone price\n";
            $askedForIPhonePrice = true;
            $message = '1200';
        } else {
            echo "AI asking for price (generic)\n";
            $message = '999';
        }
    } elseif (str_contains($lowerResponse, 'email')) {
        echo "AI asking for email\n";
        $message = 'mohamed@example.com';
    } elseif (str_contains($lowerResponse, 'phone')) {
        echo "AI asking for phone\n";
        $message = 'skip';
    } elseif (str_contains($lowerResponse, 'address')) {
        echo "AI asking for address\n";
        $message = 'skip';
    } elseif (str_contains($lowerResponse, 'confirm')) {
        echo "AI asking for confirmation\n";
        $message = 'yes';
    } elseif (str_contains($lowerResponse, 'invoice') && 
              (str_contains($lowerResponse, 'created') || str_contains($lowerResponse, 'success'))) {
        echo "✓ Invoice created successfully!\n";
        $workflowCompleted = true;
        break;
    } elseif (str_contains($lowerResponse, 'failed') || str_contains($lowerResponse, 'error')) {
        echo "✗ Workflow failed!\n";
        echo "Error: " . substr($responseText, 0, 300) . "\n";
        break;
    } else {
        echo "AI response: " . substr($responseText, 0, 100) . "...\n";
        $message = 'yes';
    }
    
    sleep(1);
    $response = sendRequest($BASE_URL, [
        'message' => $message,
        'session_id' => $sessionId,
        'memory' => true,
        'actions' => true,
    ], $AUTH_TOKEN, $CSRF_TOKEN);
    
    $step++;
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Test Results\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "Asked for Macbook price: " . ($askedForMacbookPrice ? "✓ YES" : "✗ NO") . "\n";
echo "Asked for iPhone price: " . ($askedForIPhonePrice ? "✓ YES" : "✗ NO") . "\n";
echo "Asked for category: " . ($askedForCategory ? "✓ YES" : "✗ NO") . "\n";
echo "Workflow completed: " . ($workflowCompleted ? "✓ YES" : "✗ NO") . "\n";
echo "Total steps: {$step}\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

if ($workflowCompleted && $askedForMacbookPrice && $askedForIPhonePrice && $askedForCategory) {
    echo "✓ ALL TESTS PASSED!\n\n";
    exit(0);
} else {
    echo "✗ SOME TESTS FAILED\n\n";
    exit(1);
}
