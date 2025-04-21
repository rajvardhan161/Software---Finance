<?php
header('Content-Type: application/json');


$apiKey = 'AIzaSyDLYhMKRarIF9kyeUVXZfBVQv8elXVsJMs'; 


if ($apiKey === 'YOUR_GEMINI_API_KEY' || empty($apiKey)) {
    http_response_code(500);
    error_log("Chatbot API Error: API Key is not configured in chatbot_api.php");
    echo json_encode(['error' => 'AI Service is not configured correctly. Please contact support.']);
    exit;
}


$geminiApiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash-latest:generateContent?key=' . $apiKey;


$input = json_decode(file_get_contents('php://input'), true);

if (json_last_error() !== JSON_ERROR_NONE || !isset($input['prompt']) || empty(trim($input['prompt']))) {
    http_response_code(400); 
    echo json_encode(['error' => 'Invalid input. Please provide a prompt.']);
    exit;
}

$userPrompt = trim($input['prompt']);


$systemInstruction = "You are a specialized financial assistant for a web application called FinDash. ".
                     "Your primary function is to provide helpful and friendly advice related to **personal finance, personal budgeting techniques, personal saving strategies, and understanding common financial terms relevant to individuals**. ".
                     "Keep responses concise, relevant, easy to understand, polite, and encouraging. ".

                     
                     "**If the user asks a question completely unrelated to finance (e.g., weather, politics, history, celebrities, programming), you MUST politely refuse** and clearly state that you can only assist with finance-related queries for FinDash. ".

                     
                     "**If the user asks about broader economic or governmental finance topics (like specific national budgets, economic policies, stock market analysis), acknowledge that these are finance-related but explain that your role in FinDash is focused specifically on *personal* finance.** You can offer a very brief, general definition if appropriate (e.g., explaining what a 'budget' is in general terms if asked about a national budget), but state that providing detailed analysis or specific data on these broader topics is outside your scope. Gently guide the conversation back to personal finance if possible. ".

                     
                     "Do NOT provide investment advice or recommend specific financial products. Do not perform complex calculations or provide real-time market data.";


$fullPrompt = $systemInstruction . "\n\nUser query: " . $userPrompt;


$data = [
    'contents' => [
        [
            
            'parts' => [
                ['text' => $fullPrompt]
            ]
        ]
        
    ],
    'safetySettings' => [
        ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
    ],
    'generationConfig' => [
        'temperature' => 0.6,     
        'topK' => 40,
        'topP' => 0.95,
        'maxOutputTokens' => 256, 
        
    ]
];
$jsonData = json_encode($data);


$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $geminiApiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
curl_setopt($ch, CURLOPT_TIMEOUT, 45); 

$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);


if ($curlError) {
    http_response_code(500); 
    error_log("Chatbot cURL Error: " . $curlError);
    echo json_encode(['error' => 'Failed to connect to the AI service. Please try again later.']);
    exit;
}

if ($httpcode >= 200 && $httpcode < 300) {
    $responseData = json_decode($response, true);

    
    if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        
        $botReply = $responseData['candidates'][0]['content']['parts'][0]['text'];
        echo json_encode(['reply' => $botReply]);

    } else {
        
        $finishReason = $responseData['candidates'][0]['finishReason'] ?? null;
        $safetyRatings = $responseData['candidates'][0]['safetyRatings'] ?? null;

        if ($finishReason === 'SAFETY' || (is_array($safetyRatings) && !empty(array_filter($safetyRatings, fn($rating) => isset($rating['blocked']) && $rating['blocked'] === true)))) {
             http_response_code(400); 
             error_log("Chatbot API Blocked: SAFETY - Response: " . $response);
             echo json_encode(['error' => 'My response was blocked due to safety guidelines. Please rephrase your question or ask something different.']);
        } elseif ($finishReason === 'RECITATION') {
             http_response_code(400); 
             error_log("Chatbot API Blocked: RECITATION - Response: " . $response);
             echo json_encode(['error' => 'My response potentially contained copyrighted material and was blocked. Please ask differently.']);
        } elseif ($finishReason) {
             
             http_response_code(500); 
             error_log("Chatbot API Finish Reason: " . $finishReason . " - Response: " . $response);
             echo json_encode(['error' => 'Sorry, I couldn\'t fully complete the response (' . $finishReason . '). Please try again or rephrase.']);
        } else {
             
             http_response_code(500); 
             error_log("Chatbot API Error: Could not extract text or unexpected response structure. Response: " . $response);
             
             if (isset($responseData['promptFeedback']['blockReason'])) {
                 error_log("Chatbot API Prompt Blocked: " . $responseData['promptFeedback']['blockReason']);
                 echo json_encode(['error' => 'Your request could not be processed due to content guidelines. Please rephrase.']);
             } else {
                 echo json_encode(['error' => 'Sorry, I received an unexpected response format from the AI service.']);
             }
        }
        exit; 
    }

} else {
    
    http_response_code($httpcode); 
    error_log("Chatbot API HTTP Error: " . $httpcode . " - Response: " . $response);
    $errorDetails = json_decode($response, true);
    $apiErrorMessage = $errorDetails['error']['message'] ?? 'An unknown error occurred with the AI service.';
    
    $displayErrorMessage = 'Sorry, there was an error communicating with the AI service (Code: ' . $httpcode . '). ';

    
    if (isset($errorDetails['error']['status'])) {
        switch ($errorDetails['error']['status']) {
            case 'PERMISSION_DENIED':
                $displayErrorMessage .= 'There might be an issue with the API key configuration.';
                break;
            case 'INVALID_ARGUMENT':
                 $displayErrorMessage .= 'There might be an issue with the request format sent to the AI.';
                 break;
            case 'UNAUTHENTICATED':
                 $displayErrorMessage .= 'Authentication failed. Please check the API key.';
                 break;
             case 'RESOURCE_EXHAUSTED':
                 $displayErrorMessage .= 'The AI service quota may have been exceeded. Please try again later.';
                 break;
             default:
                 $displayErrorMessage .= 'Please try again later.'; 
                 break;
         }
    } else {
         $displayErrorMessage .= 'Please try again later.'; 
    }

    echo json_encode(['error' => $displayErrorMessage]);
}

?>