@props([
    'sessionId' => 'dc-' . uniqid(),
    'configName' => '',           // Reference to registered config (required if no inline config)
    'theme' => 'light',
    'height' => '500px',
    'apiEndpoint' => '/api/v1/data-collector',
    'engine' => 'openai',
    'model' => 'gpt-4o',
    'showProgress' => true,
    'showFieldList' => true,
    'autoStart' => true,
    'language' => 'en',
    // Inline config (optional - if not provided, configName is used to fetch from server)
    'inlineConfig' => null,       // Pass inline config array if needed
])

@php
    // Only pass minimal inline config if provided, otherwise use configName reference
    $configData = $inlineConfig ? [
        'name' => $inlineConfig['name'] ?? $configName,
        'fields' => $inlineConfig['fields'] ?? [],
    ] : null;

@endphp


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
    data-inline-config="{{ $configData ? json_encode($configData) : '' }}"
    data-language="{{ $language }}">
    <!-- Header (title/description loaded from API) -->
    <div class="dc-header">
        <div class="dc-title-section">
            <h3 class="dc-title" id="title-{{ $sessionId }}">Loading...</h3>
            <p class="dc-description" id="description-{{ $sessionId }}" style="display: none;"></p>
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
        <!-- Welcome message will be added dynamically from API response -->
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
            <input 
                type="file" 
                id="file-input-{{ $sessionId }}" 
                class="dc-file-input" 
                accept=".pdf,.txt,.doc,.docx"
                style="display: none;"
            />
            <button 
                id="file-btn-{{ $sessionId }}"
                class="dc-file-btn"
                title="Upload PDF or text file to auto-fill"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                </svg>
            </button>
            <textarea
                    id="input-{{ $sessionId }}"
                    class="dc-input"
                    placeholder="{{ $language === 'ar' ? 'اكتب ردك...' : 'Type your response...' }}"
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
            <span class="dc-status" id="status-{{ $sessionId }}">
    {{ $language === 'ar' ? 'جاهز' : 'Ready' }}
</span>
            <span class="dc-char-count"><span id="char-count-{{ $sessionId }}">0</span>/2000</span>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="dc-modal" id="confirm-modal-{{ $sessionId }}" style="display: none;">
        <div class="dc-modal-backdrop"></div>
        <div class="dc-modal-content">
            <div class="dc-modal-header">
                <h4>
                    {{ $language === 'ar' ? 'تأكيد معلوماتك' : 'Confirm Your Information' }}
                </h4>
            </div>

            <div class="dc-modal-body" id="confirm-body-{{ $sessionId }}">

            </div>

            <div class="dc-modal-footer">
                <button class="dc-btn dc-btn-secondary" id="confirm-modify-{{ $sessionId }}">
                    {{ $language === 'ar' ? 'تعديل' : 'Modify' }}
                </button>

                <button class="dc-btn dc-btn-primary" id="confirm-submit-{{ $sessionId }}">
                    {{ $language === 'ar' ? 'تأكيد وإرسال' : 'Confirm & Submit' }}
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
    line-height: 1.8;
    word-wrap: break-word;
    font-size: 14px;
}

/* RTL Support for Arabic and other RTL languages */
.dc-message-text[dir="rtl"],
.dc-message-text.rtl {
    text-align: right;
    direction: rtl;
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
    padding: 12px 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    border-top: 1px solid #e5e7eb;
    background: linear-gradient(to bottom, #f8fafc 0%, #f1f5f9 100%);
    align-items: center;
}

.dark .dc-quick-actions {
    background: linear-gradient(to bottom, #1f2937 0%, #111827 100%);
    border-top-color: #374151;
}

/* Action Groups */
.dc-action-group {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
}

.dc-action-group:not(:last-child) {
    padding-right: 12px;
    border-right: 1px solid #e5e7eb;
    margin-right: 4px;
}

.dark .dc-action-group:not(:last-child) {
    border-right-color: #374151;
}

.dc-action-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #6b7280;
    margin-right: 4px;
}

.dark .dc-action-label {
    color: #9ca3af;
}

/* RTL Support for Quick Actions */
.dc-quick-actions.rtl,
.dc-quick-actions[dir="rtl"] {
    flex-direction: row-reverse;
}

.dc-action-group.rtl {
    flex-direction: row-reverse;
}

.dc-quick-btn {
    padding: 8px 16px;
    border: 1.5px solid #e5e7eb;
    background: white;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    color: #374151;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
}

.dc-quick-btn:hover {
    background: #f8fafc;
    border-color: #667eea;
    color: #667eea;
    transform: translateY(-1px);
    box-shadow: 0 4px 6px rgba(102, 126, 234, 0.15);
}

.dc-quick-btn:active {
    transform: translateY(0);
}

.dc-quick-btn.primary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    border-color: transparent;
    color: white;
    box-shadow: 0 2px 4px rgba(16, 185, 129, 0.3);
}

.dc-quick-btn.primary:hover {
    background: linear-gradient(135deg, #059669 0%, #047857 100%);
    box-shadow: 0 4px 8px rgba(16, 185, 129, 0.4);
    transform: translateY(-1px);
}

.dc-quick-btn.secondary {
    background: white;
    border-color: #d1d5db;
    color: #4b5563;
}

.dc-quick-btn.secondary:hover {
    background: #f9fafb;
    border-color: #9ca3af;
    color: #374151;
}

.dc-quick-btn.option {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-color: transparent;
    color: white;
    box-shadow: 0 2px 4px rgba(102, 126, 234, 0.3);
}

.dc-quick-btn.option:hover {
    box-shadow: 0 4px 8px rgba(102, 126, 234, 0.4);
    transform: translateY(-1px);
}

.dc-quick-btn.danger {
    background: white;
    border-color: #fca5a5;
    color: #dc2626;
}

.dc-quick-btn.danger:hover {
    background: #fef2f2;
    border-color: #f87171;
}

.dc-quick-btn.skip {
    background: transparent;
    border-color: #d1d5db;
    color: #6b7280;
    font-size: 12px;
    padding: 6px 12px;
}

.dc-quick-btn.skip:hover {
    background: #f3f4f6;
    color: #4b5563;
}

.dark .dc-quick-btn {
    background: #374151;
    border-color: #4b5563;
    color: #e5e7eb;
}

.dark .dc-quick-btn:hover {
    background: #4b5563;
    border-color: #667eea;
}

.dark .dc-quick-btn.primary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
}

.dark .dc-quick-btn.option {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.dark .dc-quick-btn.danger {
    background: #374151;
    border-color: #f87171;
    color: #f87171;
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

.dc-file-btn {
    padding: 10px;
    border: 1px solid #d1d5db;
    background: white;
    border-radius: 50%;
    cursor: pointer;
    color: #6b7280;
    transition: all 0.2s;
    flex-shrink: 0;
}

.dc-file-btn:hover {
    background: #f3f4f6;
    border-color: #667eea;
    color: #667eea;
}

.dc-file-btn.loading {
    animation: pulse 1s infinite;
    pointer-events: none;
}

.dark .dc-file-btn {
    background: #374151;
    border-color: #4b5563;
    color: #9ca3af;
}

.dark .dc-file-btn:hover {
    background: #4b5563;
    color: #667eea;
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
    
    // File upload
    const fileInput = document.getElementById('file-input-{{ $sessionId }}');
    const fileBtn = document.getElementById('file-btn-{{ $sessionId }}');
    
    // Modals
    const confirmModal = document.getElementById('confirm-modal-{{ $sessionId }}');
    const confirmBody = document.getElementById('confirm-body-{{ $sessionId }}');
    const confirmModifyBtn = document.getElementById('confirm-modify-{{ $sessionId }}');
    const confirmSubmitBtn = document.getElementById('confirm-submit-{{ $sessionId }}');
    const successModal = document.getElementById('success-modal-{{ $sessionId }}');
    const successMessage = document.getElementById('success-message-{{ $sessionId }}');
    const successCloseBtn = document.getElementById('success-close-{{ $sessionId }}');
    
    // Header elements for dynamic update
    const titleEl = document.getElementById('title-{{ $sessionId }}');
    const descriptionEl = document.getElementById('description-{{ $sessionId }}');
    
    const sessionId = container.dataset.sessionId;
    const configName = container.dataset.configName;
    const apiEndpoint = container.dataset.apiEndpoint;
    const engine = container.dataset.engine;
    const model = container.dataset.model;
    const autoStart = container.dataset.autoStart === 'true';
    const inlineConfig = container.dataset.inlineConfig ? JSON.parse(container.dataset.inlineConfig) : null;
    const language = container.dataset.language || 'en';
    
    let currentState = null;
    let fields = [];
    let config = {};  // Will be populated from API response
    
    // Initialize
    if (autoStart) {
        startSession();
    }
    
    // Event Listeners
    input.addEventListener('input', handleInputChange);
    input.addEventListener('keydown', handleKeyDown);
    sendBtn.addEventListener('click', sendMessage);
    cancelBtn.addEventListener('click', cancelSession);
    
    // File upload listeners
    if (fileBtn && fileInput) {
        fileBtn.addEventListener('click', () => fileInput.click());
        fileInput.addEventListener('change', handleFileUpload);
    }
    
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
            // Use /start-custom if inline config is provided, otherwise use /start with config_name
            const hasInlineConfig = inlineConfig && inlineConfig.fields && Object.keys(inlineConfig.fields).length > 0;
            const endpoint = hasInlineConfig ? `${apiEndpoint}/start-custom` : `${apiEndpoint}/start`;
            
            const requestBody = hasInlineConfig ? {
                session_id: sessionId,
                name: inlineConfig.name || configName || 'inline_config',
                fields: inlineConfig.fields,
                language: language,
            } : {
                session_id: sessionId,
                config_name: configName,
                language: language,
            };
            
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify(requestBody)
            });
            
            const data = await response.json();
            hideTyping();
            
            if (data.success) {
                currentState = data;
                
                // Store config from API response (includes all settings like actionSummary, etc.)
                config = data.config || data.metadata?.config || {};
                
                // Update header with config data from API
                updateHeader(data);
                
                // Initialize fields from API response
                initializeFieldsFromConfig(data);
                
                updateUI(data);
                addMessage('assistant', data.message);
                showQuickActions(data);
            } else {
                addMessage('assistant', data.message || 'Failed to start session.');
                if (titleEl) titleEl.textContent = 'Error';
            }
            
            updateStatus('{{ $language === 'ar' ? 'جاهز' : 'Ready' }}');
        } catch (error) {
            hideTyping();
            addMessage('assistant', 'Failed to connect. Please try again.');
            updateStatus('Error');
            if (titleEl) titleEl.textContent = 'Connection Error';
            console.error('Start session error:', error);
        }
    }
    
    function updateHeader(data) {
        const configData = data.config || data.metadata?.config || {};
        
        // Update title
        if (titleEl) {
            titleEl.textContent = configData.title || data.config_name || configName || 'Data Collection';
        }
        
        // Update description
        if (descriptionEl) {
            const desc = configData.description || '';
            if (desc) {
                descriptionEl.textContent = desc;
                descriptionEl.style.display = 'block';
            } else {
                descriptionEl.style.display = 'none';
            }
        }
    }
    
    async function sendMessage(overrideMessage = null) {

        const message = typeof overrideMessage === 'string' ? overrideMessage : input.value.trim();
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
                    config_name: config.name || currentState?.config_name || configName,
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
                // Scroll after quick actions are rendered
                setTimeout(scrollToBottom, 50);
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
    
    async function handleFileUpload(event) {
        const file = event.target.files[0];
        if (!file) return;
        
        // Validate file type
        const allowedTypes = ['application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!allowedTypes.includes(file.type) && !file.name.match(/\.(pdf|txt|doc|docx)$/i)) {
            addMessage('assistant', currentLang === 'ar' 
                ? 'يرجى تحميل ملف PDF أو نص فقط.' 
                : 'Please upload a PDF or text file only.');
            return;
        }
        
        // Validate file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            addMessage('assistant', currentLang === 'ar' 
                ? 'حجم الملف كبير جداً. الحد الأقصى 10 ميجابايت.' 
                : 'File is too large. Maximum size is 10MB.');
            return;
        }
        
        fileBtn.classList.add('loading');
        updateStatus(currentLang === 'ar' ? 'جاري تحليل الملف...' : 'Analyzing file...');
        addMessage('user', currentLang === 'ar' 
            ? `📎 تم تحميل: ${file.name}` 
            : `📎 Uploaded: ${file.name}`);
        showTyping();
        
        try {
            const formData = new FormData();
            formData.append('file', file);
            formData.append('session_id', sessionId);
            formData.append('language', language);
            
            // Add full field config (including validation rules) for AI extraction
            if (config.fields) {
                formData.append('fields', JSON.stringify(Object.keys(config.fields)));
                formData.append('field_config', JSON.stringify(config.fields));
            }
            
            const response = await fetch(`${apiEndpoint}/analyze-file`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: formData
            });
            
            const data = await response.json();
            hideTyping();
            fileBtn.classList.remove('loading');
            
            if (data.success && data.extracted_data) {
                // Show extracted data summary
                const summaryMsg = currentLang === 'ar' 
                    ? `✅ تم استخراج البيانات من الملف:\n\n${formatExtractedData(data.extracted_data)}\n\nهل تريد استخدام هذه البيانات؟`
                    : `✅ Extracted data from file:\n\n${formatExtractedData(data.extracted_data)}\n\nWould you like to use this data?`;
                
                addMessage('assistant', summaryMsg);
                
                // Store extracted data for confirmation
                window.extractedFileData = data.extracted_data;
                
                // Show confirm/modify buttons
                showFileDataActions();
            } else {
                addMessage('assistant', data.message || (currentLang === 'ar' 
                    ? 'لم نتمكن من استخراج البيانات من الملف.' 
                    : 'Could not extract data from the file.'));
            }
            
            updateStatus('Ready');
        } catch (error) {
            hideTyping();
            fileBtn.classList.remove('loading');
            addMessage('assistant', currentLang === 'ar' 
                ? 'فشل في تحليل الملف. يرجى المحاولة مرة أخرى.' 
                : 'Failed to analyze file. Please try again.');
            updateStatus('Error');
            console.error('File upload error:', error);
        }
        
        // Reset file input
        fileInput.value = '';
    }
    
    function formatExtractedData(data) {
        let formatted = '';
        for (const [key, value] of Object.entries(data)) {
            if (value) {
                const label = key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' ');
                formatted += `• **${label}**: ${value}\n`;
            }
        }
        return formatted || (currentLang === 'ar' ? 'لا توجد بيانات' : 'No data');
    }
    
    function showFileDataActions() {
        const html = `
            <div class="dc-action-group">
                <button class="dc-quick-btn primary" data-action="use-file-data">${currentLang === 'ar' ? '✓ استخدام البيانات' : '✓ Use Data'}</button>
                <button class="dc-quick-btn secondary" data-action="modify-file-data">${currentLang === 'ar' ? '✎ تعديل' : '✎ Modify'}</button>
                <button class="dc-quick-btn danger" data-action="discard-file-data">${currentLang === 'ar' ? '✕ تجاهل' : '✕ Discard'}</button>
            </div>
        `;
        quickActionsContainer.innerHTML = html;
        quickActionsContainer.style.display = 'flex';
        
        // Add click handlers
        quickActionsContainer.querySelector('[data-action="use-file-data"]')?.addEventListener('click', useExtractedData);
        quickActionsContainer.querySelector('[data-action="modify-file-data"]')?.addEventListener('click', modifyExtractedData);
        quickActionsContainer.querySelector('[data-action="discard-file-data"]')?.addEventListener('click', discardExtractedData);
        
        setTimeout(scrollToBottom, 10);
    }
    
    async function useExtractedData() {
        if (!window.extractedFileData) return;
        
        hideQuickActions();
        showTyping();
        updateStatus(currentLang === 'ar' ? 'جاري تطبيق البيانات...' : 'Applying data...');
        
        try {
            const response = await fetch(`${apiEndpoint}/apply-extracted`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    session_id: sessionId,
                    extracted_data: window.extractedFileData,
                    language: language
                })
            });
            
            const data = await response.json();
            hideTyping();
            
            if (data.success) {
                currentState = data;
                updateUI(data);
                addMessage('assistant', data.message);
                
                if (data.requires_confirmation || data.metadata?.requires_confirmation) {
                    showQuickActions(data);
                }
            } else {
                addMessage('assistant', data.message || 'Failed to apply data.');
            }
            
            updateStatus('Ready');
        } catch (error) {
            hideTyping();
            addMessage('assistant', 'Failed to apply extracted data.');
            updateStatus('Error');
            console.error('Apply extracted data error:', error);
        }
        
        window.extractedFileData = null;
    }
    
    function modifyExtractedData() {
        hideQuickActions();
        addMessage('assistant', currentLang === 'ar' 
            ? 'حسناً، دعنا نراجع البيانات معاً. سأسألك عن كل حقل.' 
            : 'Okay, let\'s review the data together. I\'ll ask you about each field.');
        window.extractedFileData = null;
        // Continue with normal flow
        showQuickActions(currentState || {});
    }
    
    function discardExtractedData() {
        hideQuickActions();
        addMessage('assistant', currentLang === 'ar' 
            ? 'تم تجاهل البيانات المستخرجة. يمكنك الاستمرار في إدخال البيانات يدوياً.' 
            : 'Extracted data discarded. You can continue entering data manually.');
        window.extractedFileData = null;
        showQuickActions(currentState || {});
    }
    
    function updateUI(data) {
        // Data can be at root level or in metadata object
        const meta = data.metadata || data;
        
        // Update progress
        const progress = meta.progress ?? data.progress;
        if (progressFill && progress !== undefined) {
            progressFill.style.width = `${progress}%`;
            const completeText = currentLang === 'ar' ? 'مكتمل' : 'complete';
            progressText.textContent = `${Math.round(progress)}% ${completeText}`;
        }
        
        // Update field counter - check multiple sources for collected fields
        const collectedFields = meta.collected_fields || data.collected_fields || data.collectedFields || [];
        const remainingFields = meta.remaining_fields || data.remaining_fields || [];
        const totalFields = meta.fields?.length || fields.length || (collectedFields.length + remainingFields.length);
        
        if (fieldCounter && totalFields > 0) {
            const collected = collectedFields.length || (progress === 100 ? totalFields : 0);
            if (currentLang === 'ar') {
                fieldCounter.textContent = `${collected} من ${totalFields} حقول`;
            } else {
                fieldCounter.textContent = `${collected} of ${totalFields} fields`;
            }
        }
        
        // Update fields list
        if (fieldsList && (collectedFields.length > 0 || remainingFields.length > 0)) {
            updateFieldsList({
                collected_fields: collectedFields,
                remaining_fields: remainingFields,
                current_field: meta.current_field || data.current_field,
                validation_errors: meta.validation_errors || data.validation_errors
            });
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
        // Use field description from config if available (supports localized labels)
        if (config.fields && config.fields[field] && config.fields[field].description) {
            return config.fields[field].description;
        }
        // Fallback to title case conversion
        return field.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }
    
    function initializeFieldsFromConfig(data) {
        // Get fields from API response metadata
        const meta = data.metadata || {};
        let allFieldNames = meta.fields || data.fields || [];
        
        // Store field definitions from API for use in quick actions
        const fieldDefs = data.field_definitions || meta.field_definitions || {};
        if (Object.keys(fieldDefs).length > 0) {
            // Convert field definitions to config.fields format for quick actions
            config.fields = {};
            for (const [name, def] of Object.entries(fieldDefs)) {
                config.fields[name] = {
                    options: def.options || [],
                    required: def.required ?? true,
                    type: def.type || 'text',
                    description: def.description || '',
                };
            }
        }
        
        // Fallback to inline config if no fields from API
        if (allFieldNames.length === 0 && inlineConfig?.fields) {
            allFieldNames = Object.keys(inlineConfig.fields);
        }
        
        // Set up collected/remaining fields if not present
        if (!data.metadata) {
            data.metadata = {};
        }
        
        if (!data.metadata.collected_fields) {
            data.metadata.collected_fields = [];
        }
        
        if (!data.metadata.remaining_fields || data.metadata.remaining_fields.length === 0) {
            data.metadata.remaining_fields = allFieldNames;
        }
        
        // Set current field if not set
        if (!data.metadata.current_field && allFieldNames.length > 0) {
            data.metadata.current_field = allFieldNames[0];
        }
        
        // Calculate progress
        const total = allFieldNames.length;
        const collected = data.metadata.collected_fields.length;
        data.metadata.progress = total > 0 ? Math.round((collected / total) * 100) : 0;
    }
    
    function showQuickActions(data) {
        if (!quickActionsContainer) return;
        
        const meta = data.metadata || data;
        const currentField = meta.current_field || data.current_field;
        const requiresConfirmation = meta.requires_confirmation || data.requires_confirmation;
        
        // Detect language from the AI's response
        if (data.message) {
            currentLang = detectLanguage(data.message);
        }
        
        // Track unique values to prevent duplicates
        const seenValues = new Set();
        
        // Separate action groups
        const confirmActions = [];
        const optionActions = [];
        const otherActions = [];
        
        // Helper to add action if not duplicate
        const addAction = (action, targetArray) => {
            const normalizedValue = action.value.toString().toLowerCase();
            if (!seenValues.has(normalizedValue)) {
                seenValues.add(normalizedValue);
                targetArray.push(action);
            }
        };
        
        // Add options for select fields from config (priority)
        if (currentField && config.fields?.[currentField]?.options) {
            const options = config.fields[currentField].options;
            options.forEach(opt => {
                addAction({ 
                    label: translateOption(opt), 
                    value: opt, 
                    type: 'option' 
                }, optionActions);
            });
        }
        
        // Process API actions
        if (data.actions && Array.isArray(data.actions) && data.actions.length > 0) {
            data.actions.forEach(action => {
                const reply = action.data?.reply || action.label;
                const label = action.label;
                const normalizedReply = reply.toString().toLowerCase();
                
                // Categorize actions - use translated labels
                if (normalizedReply === 'yes' || normalizedReply === 'confirm') {
                    addAction({ label: t('confirm'), value: reply, type: 'primary', group: 'confirm' }, confirmActions);
                } else if (normalizedReply === 'no' || normalizedReply === 'change' || normalizedReply === 'modify') {
                    addAction({ label: t('modify'), value: reply, type: 'secondary', group: 'confirm' }, confirmActions);
                } else if (normalizedReply === 'cancel') {
                    addAction({ label: t('cancel'), value: reply, type: 'danger', group: 'other' }, otherActions);
                } else if (!seenValues.has(normalizedReply)) {
                    // Skip if it's an option we already have
                    addAction({ label, value: reply, type: 'secondary' }, otherActions);
                }
            });
        }
        
        // Add confirmation buttons if requires confirmation and not already present
        // Also check if message contains confirmation keywords (in multiple languages)
        const messageText = (data.message || '').toLowerCase();
        const needsConfirmation = requiresConfirmation || 
            messageText.includes('confirm') || 
            messageText.includes('please confirm') ||
            messageText.includes('is this correct') ||
            messageText.includes('let me know if') ||
            messageText.includes('تأكيد') ||
            messageText.includes('هل هذا صحيح') ||
            messageText.includes('يرجى التأكيد');
            
        if (needsConfirmation && optionActions.length === 0) {
            if (!seenValues.has('yes') && !seenValues.has('confirm')) {
                addAction({ label: t('confirm'), value: 'yes', type: 'primary' }, confirmActions);
            }
            if (!seenValues.has('no') && !seenValues.has('change')) {
                addAction({ label: t('modify'), value: 'no', type: 'secondary' }, confirmActions);
            }
        }
        
        // Add skip button for optional fields
        if (currentField && config.fields?.[currentField] && !config.fields[currentField].required) {
            addAction({ label: t('skip'), value: 'skip', type: 'skip' }, otherActions);
        }
        
        // Build HTML with grouped actions
        let html = '';
        const isRtlLang = currentLang === 'ar';
        
        if (optionActions.length > 0) {
            html += `<div class="dc-action-group${isRtlLang ? ' rtl' : ''}">`;
            html += `<span class="dc-action-label">${t('choose')}</span>`;
            html += optionActions.map(action => 
                `<button class="dc-quick-btn ${action.type || ''}" data-value="${action.value}">${action.label}</button>`
            ).join('');
            html += `</div>`;
        }
        
        if (confirmActions.length > 0) {
            html += `<div class="dc-action-group${isRtlLang ? ' rtl' : ''}">`;
            html += confirmActions.map(action => 
                `<button class="dc-quick-btn ${action.type || ''}" data-value="${action.value}">${action.label}</button>`
            ).join('');
            html += `</div>`;
        }
        
        if (otherActions.length > 0) {
            html += `<div class="dc-action-group${isRtlLang ? ' rtl' : ''}">`;
            html += otherActions.map(action => 
                `<button class="dc-quick-btn ${action.type || ''}" data-value="${action.value}">${action.label}</button>`
            ).join('');
            html += `</div>`;
        }
        
        if (html) {
            quickActionsContainer.innerHTML = html;
            quickActionsContainer.style.display = 'flex';
            // Apply RTL to container if needed
            if (isRtlLang) {
                quickActionsContainer.classList.add('rtl');
                quickActionsContainer.setAttribute('dir', 'rtl');
            } else {
                quickActionsContainer.classList.remove('rtl');
                quickActionsContainer.removeAttribute('dir');
            }
            // Scroll to show the new buttons
            setTimeout(scrollToBottom, 10);
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
        
        // Get data from root level or metadata
        const meta = data.metadata || {};
        const collectedData = data.data || meta.data || {};
        const actionSummary = data.action_summary || meta.action_summary || meta.config?.action_summary;
        const configActionSummary = meta.config?.action_summary;
        
        let html = '<div class="dc-summary">';
        
        if (collectedData && Object.keys(collectedData).length > 0) {
            Object.entries(collectedData).forEach(([key, value]) => {
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
        
        // Show action summary (dynamic from AI or static from config)
        const summaryToShow = actionSummary || configActionSummary;
        if (summaryToShow) {
            const whatWillHappen = currentLang === 'ar' ? 'ما سيحدث:' : 'What will happen:';
            html += `<div class="dc-action-preview"><strong>${whatWillHappen}</strong><br>${formatMessage(summaryToShow)}</div>`;
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
        
        // Apply RTL if content contains RTL characters
        if (isRTL(content)) {
            textDiv.classList.add('rtl');
            textDiv.setAttribute('dir', 'rtl');
        }
        
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
    
    function isRTL(text) {
        // Check if text contains RTL characters (Arabic, Hebrew, Persian, etc.)
        const rtlRegex = /[\u0591-\u07FF\u200F\u202B\u202E\uFB1D-\uFDFD\uFE70-\uFEFC]/;
        return rtlRegex.test(text);
    }
    
    // Current detected language (initialized from prop)
    let currentLang = language;
    
    // Translations for UI elements
    const translations = {
        en: {
            confirm: '✓ Confirm',
            modify: '✎ Modify',
            cancel: '✕ Cancel',
            skip: 'Skip →',
            choose: 'Choose:',
            beginner: 'Beginner',
            intermediate: 'Intermediate',
            advanced: 'Advanced',
            yes: 'Yes',
            no: 'No'
        },
        ar: {
            confirm: '✓ تأكيد',
            modify: '✎ تعديل',
            cancel: '✕ إلغاء',
            skip: '← تخطي',
            choose: 'اختر:',
            beginner: 'مبتدئ',
            intermediate: 'متوسط',
            advanced: 'متقدم',
            yes: 'نعم',
            no: 'لا'
        }
    };
    
    function detectLanguage(text) {
        // Detect Arabic
        if (/[\u0600-\u06FF]/.test(text)) {
            return 'ar';
        }
        return 'en';
    }
    
    function t(key) {
        return translations[currentLang]?.[key] || translations['en'][key] || key;
    }
    
    function translateOption(option) {
        const lowerOption = option.toLowerCase();
        if (translations[currentLang]?.[lowerOption]) {
            return translations[currentLang][lowerOption];
        }
        // Capitalize first letter for display
        return option.charAt(0).toUpperCase() + option.slice(1);
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
