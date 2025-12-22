@props([
    'sessionId' => 'dc-' . uniqid(),
    'configName' => '',
    'title' => 'Data Collection',
    'description' => '',
    'theme' => 'light',
    'height' => '500px',
    'apiEndpoint' => '/api/v1/data-collector',
    'engine' => 'openai',
    'model' => 'gpt-4o',
    'showProgress' => true,
    'showFieldList' => true,
    'autoStart' => true,
    'config' => [],
])

<div 
    id="data-collector-{{ $sessionId }}" 
    class="data-collector-container {{ $theme }}"
    style="height: {{ $height }}"
    data-session-id="{{ $sessionId }}"
    data-config-name="{{ $configName }}"
    data-api-endpoint="{{ $apiEndpoint }}"
    data-engine="{{ $engine }}"
    data-model="{{ $model }}"
    data-auto-start="{{ $autoStart ? 'true' : 'false' }}"
    data-config="{{ json_encode($config) }}"
>
    <!-- Header -->
    <div class="dc-header">
        <div class="dc-title-section">
            <h3 class="dc-title">{{ $title }}</h3>
            @if($description)
                <p class="dc-description">{{ $description }}</p>
            @endif
        </div>
        <div class="dc-header-actions">
            <button class="dc-action-btn dc-cancel-btn" id="cancel-{{ $sessionId }}" title="Cancel">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </button>
        </div>
    </div>

    <!-- Progress Bar -->
    @if($showProgress)
    <div class="dc-progress-section">
        <div class="dc-progress-bar">
            <div class="dc-progress-fill" id="progress-fill-{{ $sessionId }}" style="width: 0%"></div>
        </div>
        <div class="dc-progress-info">
            <span class="dc-progress-text" id="progress-text-{{ $sessionId }}">0% complete</span>
            <span class="dc-field-counter" id="field-counter-{{ $sessionId }}">0 of 0 fields</span>
        </div>
    </div>
    @endif

    <!-- Field List (collapsible) -->
    @if($showFieldList)
    <div class="dc-fields-section" id="fields-section-{{ $sessionId }}">
        <button class="dc-fields-toggle" id="fields-toggle-{{ $sessionId }}">
            <span>Fields</span>
            <svg class="dc-toggle-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="6 9 12 15 18 9"></polyline>
            </svg>
        </button>
        <div class="dc-fields-list" id="fields-list-{{ $sessionId }}" style="display: none;">
            <!-- Fields will be populated dynamically -->
        </div>
    </div>
    @endif

    <!-- Messages Container -->
    <div class="dc-messages" id="messages-{{ $sessionId }}">
        <!-- Welcome Message -->
        <div class="dc-message assistant">
            <div class="dc-message-avatar">
                <div class="dc-avatar-ai">AI</div>
            </div>
            <div class="dc-message-content">
                <div class="dc-message-text">
                    Hello! I'll help you collect the required information. Let's get started!
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Actions (for options/buttons) -->
    <div class="dc-quick-actions" id="quick-actions-{{ $sessionId }}" style="display: none;">
        <!-- Quick action buttons will be populated dynamically -->
    </div>

    <!-- Typing Indicator -->
    <div class="dc-typing" id="typing-{{ $sessionId }}" style="display: none;">
        <div class="dc-message assistant">
            <div class="dc-message-avatar">
                <div class="dc-avatar-ai">AI</div>
            </div>
            <div class="dc-message-content">
                <div class="dc-typing-animation">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Input Container -->
    <div class="dc-input-container">
        <div class="dc-input-wrapper">
            <textarea 
                id="input-{{ $sessionId }}"
                class="dc-input"
                placeholder="Type your response..."
                rows="1"
                maxlength="2000"
            ></textarea>
            <button 
                id="send-{{ $sessionId }}"
                class="dc-send-btn"
                disabled
            >
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22,2 15,22 11,13 2,9"/>
                </svg>
            </button>
        </div>
        <div class="dc-input-footer">
            <span class="dc-status" id="status-{{ $sessionId }}">Ready</span>
            <span class="dc-char-count"><span id="char-count-{{ $sessionId }}">0</span>/2000</span>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="dc-modal" id="confirm-modal-{{ $sessionId }}" style="display: none;">
        <div class="dc-modal-backdrop"></div>
        <div class="dc-modal-content">
            <div class="dc-modal-header">
                <h4>Confirm Your Information</h4>
            </div>
            <div class="dc-modal-body" id="confirm-body-{{ $sessionId }}">
                <!-- Summary will be inserted here -->
            </div>
            <div class="dc-modal-footer">
                <button class="dc-btn dc-btn-secondary" id="confirm-modify-{{ $sessionId }}">
                    Modify
                </button>
                <button class="dc-btn dc-btn-primary" id="confirm-submit-{{ $sessionId }}">
                    Confirm & Submit
                </button>
            </div>
        </div>
    </div>

    <!-- Success Modal -->
    <div class="dc-modal" id="success-modal-{{ $sessionId }}" style="display: none;">
        <div class="dc-modal-backdrop"></div>
        <div class="dc-modal-content dc-modal-success">
            <div class="dc-success-icon">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
            </div>
            <h4>Success!</h4>
            <p id="success-message-{{ $sessionId }}">Your information has been submitted successfully.</p>
            <button class="dc-btn dc-btn-primary" id="success-close-{{ $sessionId }}">Close</button>
        </div>
    </div>
</div>

<!-- Styles -->
<style>
.data-collector-container {
    display: flex;
    flex-direction: column;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    background: #ffffff;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    overflow: hidden;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    position: relative;
}

.data-collector-container.dark {
    background: #1f2937;
    border-color: #374151;
    color: #f9fafb;
}

/* Header */
.dc-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.dark .dc-header {
    background: linear-gradient(135deg, #4c51bf 0%, #553c9a 100%);
    border-bottom-color: #374151;
}

.dc-title {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
}

.dc-description {
    margin: 4px 0 0 0;
    font-size: 13px;
    opacity: 0.9;
}

.dc-action-btn {
    padding: 8px;
    border: none;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 8px;
    cursor: pointer;
    color: white;
    transition: all 0.2s;
}

.dc-action-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

/* Progress */
.dc-progress-section {
    padding: 12px 16px;
    background: #f9fafb;
    border-bottom: 1px solid #e5e7eb;
}

.dark .dc-progress-section {
    background: #111827;
    border-bottom-color: #374151;
}

.dc-progress-bar {
    height: 6px;
    background: #e5e7eb;
    border-radius: 3px;
    overflow: hidden;
}

.dark .dc-progress-bar {
    background: #374151;
}

.dc-progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    border-radius: 3px;
    transition: width 0.3s ease;
}

.dc-progress-info {
    display: flex;
    justify-content: space-between;
    margin-top: 8px;
    font-size: 12px;
    color: #6b7280;
}

.dark .dc-progress-info {
    color: #9ca3af;
}

/* Fields Section */
.dc-fields-section {
    border-bottom: 1px solid #e5e7eb;
}

.dark .dc-fields-section {
    border-bottom-color: #374151;
}

.dc-fields-toggle {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 16px;
    border: none;
    background: #f9fafb;
    cursor: pointer;
    font-size: 13px;
    font-weight: 500;
    color: #374151;
    transition: background 0.2s;
}

.dark .dc-fields-toggle {
    background: #111827;
    color: #d1d5db;
}

.dc-fields-toggle:hover {
    background: #f3f4f6;
}

.dark .dc-fields-toggle:hover {
    background: #1f2937;
}

.dc-toggle-icon {
    transition: transform 0.2s;
}

.dc-fields-toggle.active .dc-toggle-icon {
    transform: rotate(180deg);
}

.dc-fields-list {
    padding: 8px 16px 16px;
    background: #f9fafb;
}

.dark .dc-fields-list {
    background: #111827;
}

.dc-field-item {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 0;
    font-size: 13px;
}

.dc-field-status {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
}

.dc-field-status.pending {
    background: #e5e7eb;
    color: #9ca3af;
}

.dc-field-status.current {
    background: #fef3c7;
    color: #d97706;
    animation: pulse 1.5s infinite;
}

.dc-field-status.completed {
    background: #d1fae5;
    color: #059669;
}

.dc-field-status.error {
    background: #fee2e2;
    color: #dc2626;
}

.dc-field-name {
    flex: 1;
    color: #374151;
}

.dark .dc-field-name {
    color: #d1d5db;
}

.dc-field-name.current {
    font-weight: 600;
    color: #d97706;
}

.dc-field-required {
    font-size: 11px;
    color: #ef4444;
}

/* Messages */
.dc-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.dc-message {
    display: flex;
    gap: 10px;
    max-width: 85%;
    animation: slideIn 0.3s ease-out;
}

.dc-message.user {
    align-self: flex-end;
    flex-direction: row-reverse;
}

.dc-message-avatar {
    flex-shrink: 0;
}

.dc-avatar-ai, .dc-avatar-user {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 600;
    color: white;
}

.dc-avatar-ai {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.dc-avatar-user {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.dc-message-content {
    flex: 1;
    min-width: 0;
}

.dc-message-text {
    background: #f3f4f6;
    padding: 12px 16px;
    border-radius: 16px;
    line-height: 1.5;
    word-wrap: break-word;
    font-size: 14px;
}

.dc-message.user .dc-message-text {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.dark .dc-message-text {
    background: #374151;
    color: #f9fafb;
}

/* Quick Actions */
.dc-quick-actions {
    padding: 8px 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    border-top: 1px solid #e5e7eb;
    background: #f9fafb;
}

.dark .dc-quick-actions {
    background: #111827;
    border-top-color: #374151;
}

.dc-quick-btn {
    padding: 8px 16px;
    border: 1px solid #e5e7eb;
    background: white;
    border-radius: 20px;
    font-size: 13px;
    cursor: pointer;
    transition: all 0.2s;
    color: #374151;
}

.dc-quick-btn:hover {
    background: #f3f4f6;
    border-color: #667eea;
    color: #667eea;
}

.dc-quick-btn.primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-color: transparent;
    color: white;
}

.dc-quick-btn.primary:hover {
    opacity: 0.9;
}

.dc-quick-btn.secondary {
    background: #f3f4f6;
    border-color: #d1d5db;
}

.dc-quick-btn.skip {
    background: transparent;
    border-color: #d1d5db;
    color: #6b7280;
    font-size: 12px;
}

.dark .dc-quick-btn {
    background: #374151;
    border-color: #4b5563;
    color: #d1d5db;
}

/* Typing Indicator */
.dc-typing-animation {
    display: flex;
    gap: 4px;
    padding: 12px 16px;
    background: #f3f4f6;
    border-radius: 16px;
    width: fit-content;
}

.dark .dc-typing-animation {
    background: #374151;
}

.dc-typing-animation span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #9ca3af;
    animation: typing 1.4s infinite ease-in-out;
}

.dc-typing-animation span:nth-child(1) { animation-delay: -0.32s; }
.dc-typing-animation span:nth-child(2) { animation-delay: -0.16s; }

@keyframes typing {
    0%, 80%, 100% { transform: scale(0.8); opacity: 0.5; }
    40% { transform: scale(1); opacity: 1; }
}

@keyframes slideIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Input */
.dc-input-container {
    border-top: 1px solid #e5e7eb;
    padding: 12px 16px;
    background: #ffffff;
}

.dark .dc-input-container {
    border-top-color: #374151;
    background: #1f2937;
}

.dc-input-wrapper {
    display: flex;
    gap: 10px;
    align-items: flex-end;
}

.dc-input {
    flex: 1;
    border: 1px solid #d1d5db;
    border-radius: 20px;
    padding: 10px 16px;
    font-size: 14px;
    line-height: 1.4;
    resize: none;
    max-height: 100px;
    background: white;
    transition: border-color 0.2s;
}

.dc-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.dark .dc-input {
    background: #374151;
    border-color: #4b5563;
    color: #f9fafb;
}

.dc-send-btn {
    padding: 10px;
    border: none;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border-radius: 50%;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
}

.dc-send-btn:hover:not(:disabled) {
    transform: scale(1.05);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.dc-send-btn:disabled {
    background: #d1d5db;
    cursor: not-allowed;
}

.dark .dc-send-btn:disabled {
    background: #4b5563;
}

.dc-input-footer {
    display: flex;
    justify-content: space-between;
    margin-top: 6px;
    font-size: 11px;
    color: #9ca3af;
}

/* Modal */
.dc-modal {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 100;
}

.dc-modal-backdrop {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
}

.dc-modal-content {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    background: white;
    border-radius: 12px;
    width: 90%;
    max-width: 400px;
    max-height: 80%;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
}

.dark .dc-modal-content {
    background: #1f2937;
}

.dc-modal-header {
    padding: 16px;
    border-bottom: 1px solid #e5e7eb;
}

.dark .dc-modal-header {
    border-bottom-color: #374151;
}

.dc-modal-header h4 {
    margin: 0;
    font-size: 16px;
    font-weight: 600;
}

.dc-modal-body {
    padding: 16px;
    overflow-y: auto;
    flex: 1;
    font-size: 14px;
    line-height: 1.6;
}

.dc-modal-footer {
    padding: 12px 16px;
    border-top: 1px solid #e5e7eb;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}

.dark .dc-modal-footer {
    border-top-color: #374151;
}

.dc-btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.dc-btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.dc-btn-primary:hover {
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.dc-btn-secondary {
    background: #f3f4f6;
    color: #374151;
}

.dc-btn-secondary:hover {
    background: #e5e7eb;
}

.dark .dc-btn-secondary {
    background: #374151;
    color: #d1d5db;
}

/* Success Modal */
.dc-modal-success {
    text-align: center;
    padding: 32px 24px;
}

.dc-success-icon {
    color: #10b981;
    margin-bottom: 16px;
}

.dc-modal-success h4 {
    margin: 0 0 8px 0;
    font-size: 20px;
}

.dc-modal-success p {
    margin: 0 0 24px 0;
    color: #6b7280;
}

/* Summary Styles */
.dc-summary {
    background: #f9fafb;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 12px;
}

.dark .dc-summary {
    background: #111827;
}

.dc-summary-item {
    display: flex;
    padding: 6px 0;
    border-bottom: 1px solid #e5e7eb;
}

.dark .dc-summary-item {
    border-bottom-color: #374151;
}

.dc-summary-item:last-child {
    border-bottom: none;
}

.dc-summary-label {
    font-weight: 500;
    color: #6b7280;
    width: 40%;
}

.dc-summary-value {
    flex: 1;
    color: #111827;
}

.dark .dc-summary-value {
    color: #f9fafb;
}

/* Responsive */
@media (max-width: 768px) {
    .data-collector-container {
        height: 100vh !important;
        border-radius: 0;
        border: none;
    }
    
    .dc-message {
        max-width: 90%;
    }
}

/* Scrollbar */
.dc-messages::-webkit-scrollbar {
    width: 6px;
}

.dc-messages::-webkit-scrollbar-track {
    background: transparent;
}

.dc-messages::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 3px;
}

.dark .dc-messages::-webkit-scrollbar-thumb {
    background: #4b5563;
}
</style>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('data-collector-{{ $sessionId }}');
    const messagesContainer = document.getElementById('messages-{{ $sessionId }}');
    const input = document.getElementById('input-{{ $sessionId }}');
    const sendBtn = document.getElementById('send-{{ $sessionId }}');
    const typingIndicator = document.getElementById('typing-{{ $sessionId }}');
    const quickActionsContainer = document.getElementById('quick-actions-{{ $sessionId }}');
    const cancelBtn = document.getElementById('cancel-{{ $sessionId }}');
    const charCount = document.getElementById('char-count-{{ $sessionId }}');
    const statusEl = document.getElementById('status-{{ $sessionId }}');
    
    // Progress elements
    const progressFill = document.getElementById('progress-fill-{{ $sessionId }}');
    const progressText = document.getElementById('progress-text-{{ $sessionId }}');
    const fieldCounter = document.getElementById('field-counter-{{ $sessionId }}');
    
    // Fields section
    const fieldsToggle = document.getElementById('fields-toggle-{{ $sessionId }}');
    const fieldsList = document.getElementById('fields-list-{{ $sessionId }}');
    
    // Modals
    const confirmModal = document.getElementById('confirm-modal-{{ $sessionId }}');
    const confirmBody = document.getElementById('confirm-body-{{ $sessionId }}');
    const confirmModifyBtn = document.getElementById('confirm-modify-{{ $sessionId }}');
    const confirmSubmitBtn = document.getElementById('confirm-submit-{{ $sessionId }}');
    const successModal = document.getElementById('success-modal-{{ $sessionId }}');
    const successMessage = document.getElementById('success-message-{{ $sessionId }}');
    const successCloseBtn = document.getElementById('success-close-{{ $sessionId }}');
    
    const sessionId = container.dataset.sessionId;
    const configName = container.dataset.configName;
    const apiEndpoint = container.dataset.apiEndpoint;
    const engine = container.dataset.engine;
    const model = container.dataset.model;
    const autoStart = container.dataset.autoStart === 'true';
    const config = JSON.parse(container.dataset.config || '{}');
    
    let currentState = null;
    let fields = [];
    
    // Initialize
    if (autoStart) {
        startSession();
    }
    
    // Event Listeners
    input.addEventListener('input', handleInputChange);
    input.addEventListener('keydown', handleKeyDown);
    sendBtn.addEventListener('click', sendMessage);
    cancelBtn.addEventListener('click', cancelSession);
    
    if (fieldsToggle) {
        fieldsToggle.addEventListener('click', toggleFieldsList);
    }
    
    if (confirmModifyBtn) {
        confirmModifyBtn.addEventListener('click', () => {
            hideModal(confirmModal);
            sendMessage('no');
        });
    }
    
    if (confirmSubmitBtn) {
        confirmSubmitBtn.addEventListener('click', () => {
            hideModal(confirmModal);
            sendMessage('yes');
        });
    }
    
    if (successCloseBtn) {
        successCloseBtn.addEventListener('click', () => {
            hideModal(successModal);
        });
    }
    
    // Quick action button clicks
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('dc-quick-btn')) {
            const value = e.target.dataset.value;
            if (value) {
                input.value = value;
                sendMessage();
            }
        }
    });
    
    async function startSession() {
        updateStatus('Starting...');
        showTyping();
        
        try {
            const response = await fetch(`${apiEndpoint}/start`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    config_name: configName,
                    config: config,
                    engine: engine,
                    model: model
                })
            });
            
            const data = await response.json();
            hideTyping();
            
            if (data.success) {
                currentState = data;
                updateUI(data);
                addMessage('assistant', data.message);
                showQuickActions(data);
            } else {
                addMessage('assistant', data.message || 'Failed to start session.');
            }
            
            updateStatus('Ready');
        } catch (error) {
            hideTyping();
            addMessage('assistant', 'Failed to connect. Please try again.');
            updateStatus('Error');
            console.error('Start session error:', error);
        }
    }
    
    async function sendMessage(overrideMessage = null) {
        const message = overrideMessage || input.value.trim();
        if (!message) return;
        
        // Add user message
        addMessage('user', message);
        input.value = '';
        handleInputChange();
        
        showTyping();
        hideQuickActions();
        updateStatus('Processing...');
        
        try {
            const response = await fetch(`${apiEndpoint}/message`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    message: message,
                    engine: engine,
                    model: model
                })
            });
            
            const data = await response.json();
            hideTyping();
            
            currentState = data;
            updateUI(data);
            
            if (data.requires_confirmation && data.summary) {
                showConfirmationModal(data);
            } else if (data.is_complete) {
                showSuccessModal(data);
            } else {
                addMessage('assistant', data.message);
                showQuickActions(data);
            }
            
            updateStatus('Ready');
        } catch (error) {
            hideTyping();
            addMessage('assistant', 'Failed to process your message. Please try again.');
            updateStatus('Error');
            console.error('Send message error:', error);
        }
    }
    
    async function cancelSession() {
        if (!confirm('Are you sure you want to cancel?')) return;
        
        try {
            await fetch(`${apiEndpoint}/cancel`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ session_id: sessionId })
            });
            
            addMessage('assistant', 'Data collection cancelled.');
            updateStatus('Cancelled');
        } catch (error) {
            console.error('Cancel error:', error);
        }
    }
    
    function updateUI(data) {
        // Update progress
        if (progressFill && data.progress !== undefined) {
            progressFill.style.width = `${data.progress}%`;
            progressText.textContent = `${Math.round(data.progress)}% complete`;
        }
        
        // Update field counter
        if (fieldCounter && data.collected_fields && data.remaining_fields) {
            const total = data.collected_fields.length + data.remaining_fields.length;
            fieldCounter.textContent = `${data.collected_fields.length} of ${total} fields`;
        }
        
        // Update fields list
        if (fieldsList && data.collected_fields) {
            updateFieldsList(data);
        }
    }
    
    function updateFieldsList(data) {
        if (!fieldsList) return;
        
        const allFields = [...(data.collected_fields || []), ...(data.remaining_fields || [])];
        
        fieldsList.innerHTML = allFields.map(field => {
            const isCollected = data.collected_fields?.includes(field);
            const isCurrent = data.current_field === field;
            const hasError = data.validation_errors?.[field];
            
            let statusClass = 'pending';
            let statusIcon = '○';
            
            if (hasError) {
                statusClass = 'error';
                statusIcon = '✕';
            } else if (isCollected) {
                statusClass = 'completed';
                statusIcon = '✓';
            } else if (isCurrent) {
                statusClass = 'current';
                statusIcon = '●';
            }
            
            return `
                <div class="dc-field-item">
                    <span class="dc-field-status ${statusClass}">${statusIcon}</span>
                    <span class="dc-field-name ${isCurrent ? 'current' : ''}">${formatFieldName(field)}</span>
                </div>
            `;
        }).join('');
    }
    
    function formatFieldName(field) {
        return field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }
    
    function showQuickActions(data) {
        if (!quickActionsContainer) return;
        
        const actions = [];
        
        // Add options for select fields
        if (data.current_field && config.fields?.[data.current_field]?.options) {
            const options = config.fields[data.current_field].options;
            options.forEach(opt => {
                actions.push({ label: opt, value: opt, type: 'option' });
            });
        }
        
        // Add confirmation buttons
        if (data.requires_confirmation) {
            actions.push({ label: '✓ Confirm', value: 'yes', type: 'primary' });
            actions.push({ label: '✎ Modify', value: 'no', type: 'secondary' });
        }
        
        // Add skip button for optional fields
        if (data.current_field && !config.fields?.[data.current_field]?.required) {
            actions.push({ label: 'Skip', value: 'skip', type: 'skip' });
        }
        
        if (actions.length > 0) {
            quickActionsContainer.innerHTML = actions.map(action => 
                `<button class="dc-quick-btn ${action.type || ''}" data-value="${action.value}">${action.label}</button>`
            ).join('');
            quickActionsContainer.style.display = 'flex';
        } else {
            quickActionsContainer.style.display = 'none';
        }
    }
    
    function hideQuickActions() {
        if (quickActionsContainer) {
            quickActionsContainer.style.display = 'none';
        }
    }
    
    function showConfirmationModal(data) {
        if (!confirmModal || !confirmBody) return;
        
        let html = '<div class="dc-summary">';
        
        if (data.data) {
            Object.entries(data.data).forEach(([key, value]) => {
                if (key.startsWith('_')) return; // Skip internal fields
                html += `
                    <div class="dc-summary-item">
                        <span class="dc-summary-label">${formatFieldName(key)}</span>
                        <span class="dc-summary-value">${value || '-'}</span>
                    </div>
                `;
            });
        }
        
        html += '</div>';
        
        if (data.action_summary) {
            html += `<div class="dc-action-preview"><strong>What will happen:</strong><br>${data.action_summary}</div>`;
        }
        
        confirmBody.innerHTML = html;
        showModal(confirmModal);
    }
    
    function showSuccessModal(data) {
        if (!successModal) return;
        
        if (successMessage && data.message) {
            successMessage.textContent = data.message;
        }
        
        showModal(successModal);
    }
    
    function showModal(modal) {
        modal.style.display = 'block';
    }
    
    function hideModal(modal) {
        modal.style.display = 'none';
    }
    
    function addMessage(role, content) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `dc-message ${role}`;
        
        const avatarDiv = document.createElement('div');
        avatarDiv.className = 'dc-message-avatar';
        avatarDiv.innerHTML = `<div class="dc-avatar-${role}">${role === 'user' ? 'You' : 'AI'}</div>`;
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'dc-message-content';
        
        const textDiv = document.createElement('div');
        textDiv.className = 'dc-message-text';
        textDiv.innerHTML = formatMessage(content);
        
        contentDiv.appendChild(textDiv);
        messageDiv.appendChild(avatarDiv);
        messageDiv.appendChild(contentDiv);
        
        messagesContainer.appendChild(messageDiv);
        scrollToBottom();
    }
    
    function formatMessage(content) {
        // Convert markdown-like formatting
        return content
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');
    }
    
    function showTyping() {
        typingIndicator.style.display = 'block';
        scrollToBottom();
    }
    
    function hideTyping() {
        typingIndicator.style.display = 'none';
    }
    
    function updateStatus(status) {
        if (statusEl) {
            statusEl.textContent = status;
        }
    }
    
    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    
    function handleInputChange() {
        const value = input.value;
        charCount.textContent = value.length;
        sendBtn.disabled = value.trim().length === 0;
        
        // Auto-resize
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 100) + 'px';
    }
    
    function handleKeyDown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!sendBtn.disabled) {
                sendMessage();
            }
        }
    }
    
    function toggleFieldsList() {
        if (!fieldsList || !fieldsToggle) return;
        
        const isVisible = fieldsList.style.display !== 'none';
        fieldsList.style.display = isVisible ? 'none' : 'block';
        fieldsToggle.classList.toggle('active', !isVisible);
    }
});
</script>
