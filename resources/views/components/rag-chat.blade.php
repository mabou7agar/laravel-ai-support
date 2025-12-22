@props([
    'sessionId' => 'rag-chat-' . uniqid(),
    'engine' => 'openai',
    'model' => 'gpt-4o',
    'theme' => 'light',
    'height' => '700px',
    'memory' => true,
    'actions' => true,
    'useIntelligentRAG' => true,
    'ragCollections' => [],
    'placeholder' => 'Ask me anything...',
    'showSources' => true,
    'showActions' => true,
    'showOptions' => true,
])

<div 
    id="rag-chat-{{ $sessionId }}" 
    class="rag-chat-container {{ $theme }}"
    style="height: {{ $height }}"
    data-session-id="{{ $sessionId }}"
    data-engine="{{ $engine }}"
    data-model="{{ $model }}"
    data-memory="{{ $memory ? 'true' : 'false' }}"
    data-actions="{{ $actions ? 'true' : 'false' }}"
    data-use-intelligent-rag="{{ $useIntelligentRAG ? 'true' : 'false' }}"
    data-rag-collections="{{ json_encode($ragCollections) }}"
>
    <!-- Chat Header -->
    <div class="rag-chat-header">
        <div class="header-left">
            <div class="status-indicator">
                <span class="status-dot connected"></span>
                <span class="status-text">RAG Enabled</span>
            </div>
        </div>
        
        <div class="header-center">
            <div class="chat-info">
                <span class="engine-badge">{{ strtoupper($engine) }}</span>
                <span class="model-name">{{ $model }}</span>
            </div>
        </div>
        
        <div class="header-right">
            <button class="header-btn" onclick="clearChat{{ $sessionId }}()" title="Clear Chat">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"/>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Messages Container -->
    <div class="rag-chat-messages" id="messages-{{ $sessionId }}">
        <!-- Welcome Message -->
        <div class="message assistant welcome">
            <div class="message-avatar">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M12 2L2 7l10 5 10-5-10-5z"/>
                    <path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
            </div>
            <div class="message-content">
                <p>Hello! I'm your AI assistant with RAG capabilities. I can search through your knowledge base to provide accurate, context-aware responses.</p>
                <p>Try asking me about topics in your indexed content!</p>
            </div>
        </div>
    </div>

    <!-- Input Area -->
    <div class="rag-chat-input">
        <div class="input-wrapper">
            <!-- File Upload Button -->
            <input type="file" id="file-input-{{ $sessionId }}" class="hidden-file-input" accept=".pdf,.txt,.doc,.docx,.png,.jpg,.jpeg,.gif,.webp" />
            <button class="file-upload-btn" onclick="document.getElementById('file-input-{{ $sessionId }}').click()" title="Upload file (PDF, Image, Text)">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                </svg>
            </button>
            <textarea 
                id="input-{{ $sessionId }}"
                class="chat-input"
                placeholder="{{ $placeholder }}"
                rows="1"
                onkeydown="handleKeyDown{{ $sessionId }}(event)"
            ></textarea>
            <button class="send-btn" onclick="sendMessage{{ $sessionId }}()" id="send-btn-{{ $sessionId }}">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="22" y1="2" x2="11" y2="13"/>
                    <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                </svg>
            </button>
        </div>
        <!-- File Preview -->
        <div id="file-preview-{{ $sessionId }}" class="file-preview hidden"></div>
        <div class="input-footer">
            <span class="powered-by">Powered by RAG • {{ strtoupper($engine) }} {{ $model }}</span>
        </div>
    </div>
</div>

<style>
.rag-chat-container {
    display: flex;
    flex-direction: column;
    background: #ffffff;
    border-radius: 12px;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.1);
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.rag-chat-container.dark {
    background: #1a1a1a;
    color: #ffffff;
}

/* Header */
.rag-chat-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.status-indicator {
    display: flex;
    align-items: center;
    gap: 8px;
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #4ade80;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

.engine-badge {
    background: rgba(255, 255, 255, 0.2);
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.model-name {
    font-size: 14px;
    opacity: 0.9;
}

.header-btn {
    background: rgba(255, 255, 255, 0.2);
    border: none;
    padding: 8px;
    border-radius: 8px;
    cursor: pointer;
    color: white;
    transition: all 0.2s;
}

.header-btn:hover {
    background: rgba(255, 255, 255, 0.3);
}

/* Messages */
.rag-chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    background: #f8f9fa;
}

.rag-chat-container.dark .rag-chat-messages {
    background: #2a2a2a;
}

.message {
    display: flex;
    gap: 12px;
    margin-bottom: 20px;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.message.user {
    flex-direction: row-reverse;
}

.message-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    flex-shrink: 0;
}

.message.user .message-avatar {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.message-content {
    flex: 1;
    background: white;
    padding: 12px 16px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
    max-width: 80%;
}

.rag-chat-container.dark .message-content {
    background: #3a3a3a;
}

.message.user .message-content {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

/* Clickable Option Cards */
.options-container {
    margin-top: 16px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 12px;
}

.option-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.3s ease;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}

.option-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
    opacity: 0;
    transition: opacity 0.3s;
}

.option-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(102, 126, 234, 0.4);
    border-color: rgba(255, 255, 255, 0.3);
}

.option-card:hover::before {
    opacity: 1;
}

.option-card:active {
    transform: translateY(-2px);
}

.option-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    background: rgba(255, 255, 255, 0.2);
    border-radius: 50%;
    font-weight: 700;
    font-size: 16px;
    color: white;
    margin-bottom: 8px;
}

.option-text {
    color: white;
    font-size: 15px;
    font-weight: 500;
    line-height: 1.5;
    margin: 0;
}

.option-icon {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    opacity: 0;
    transition: all 0.3s;
}

.option-card:hover .option-icon {
    opacity: 1;
    transform: translateY(-50%) translateX(0);
}

@media (max-width: 768px) {
    .options-container {
        grid-template-columns: 1fr;
    }
}

/* RAG Sources */
.rag-sources {
    margin-top: 12px;
    padding: 12px;
    background: #f0f4ff;
    border-radius: 8px;
    border-left: 4px solid #667eea;
}

.rag-chat-container.dark .rag-sources {
    background: #2a2a3a;
}

.sources-header {
    font-size: 12px;
    font-weight: 600;
    color: #667eea;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}

.source-item {
    padding: 8px;
    background: white;
    border-radius: 6px;
    margin-bottom: 6px;
    cursor: pointer;
    transition: all 0.2s;
}

.rag-chat-container.dark .source-item {
    background: #3a3a3a;
}

.source-item:hover {
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.2);
}

.source-title {
    font-size: 13px;
    font-weight: 500;
    color: #333;
    margin-bottom: 4px;
}

.rag-chat-container.dark .source-title {
    color: #ffffff;
}

.source-meta {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    color: #666;
}

.rag-chat-container.dark .source-meta {
    color: #999;
}

.source-relevance {
    background: #4ade80;
    color: white;
    padding: 2px 6px;
    border-radius: 10px;
    font-weight: 600;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 12px;
}

.action-btn {
    background: white;
    border: 2px solid #667eea;
    color: #667eea;
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
}

.action-btn:hover {
    background: #667eea;
    color: white;
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

/* Input Area */
.rag-chat-input {
    padding: 16px 20px;
    background: white;
    border-top: 1px solid #e5e7eb;
}

.rag-chat-container.dark .rag-chat-input {
    background: #2a2a2a;
    border-top-color: #3a3a3a;
}

.input-wrapper {
    display: flex;
    gap: 12px;
    align-items: flex-end;
}

.hidden-file-input {
    display: none;
}

.file-upload-btn {
    background: transparent;
    border: 2px solid #e5e7eb;
    padding: 10px;
    border-radius: 12px;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
}

.file-upload-btn:hover {
    border-color: #667eea;
    color: #667eea;
    background: rgba(102, 126, 234, 0.05);
}

.file-upload-btn.loading {
    opacity: 0.5;
    pointer-events: none;
}

.rag-chat-container.dark .file-upload-btn {
    border-color: #4a4a4a;
    color: #9ca3af;
}

.rag-chat-container.dark .file-upload-btn:hover {
    border-color: #667eea;
    color: #667eea;
}

.file-preview {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #f3f4f6;
    border-radius: 8px;
    margin-top: 8px;
    font-size: 13px;
}

.file-preview.hidden {
    display: none;
}

.file-preview .file-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.file-preview .file-remove {
    background: none;
    border: none;
    color: #ef4444;
    cursor: pointer;
    padding: 4px;
    display: flex;
    align-items: center;
}

.file-preview .file-icon {
    color: #667eea;
}

.file-preview img.file-thumbnail {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border-radius: 4px;
}

.rag-chat-container.dark .file-preview {
    background: #3a3a3a;
}

.chat-input {
    flex: 1;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    padding: 12px 16px;
    font-size: 14px;
    resize: none;
    max-height: 120px;
    font-family: inherit;
    transition: all 0.2s;
}

.chat-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.rag-chat-container.dark .chat-input {
    background: #3a3a3a;
    border-color: #4a4a4a;
    color: white;
}

.send-btn {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border: none;
    padding: 12px;
    border-radius: 12px;
    color: white;
    cursor: pointer;
    transition: all 0.2s;
}

.send-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
}

.send-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.input-footer {
    margin-top: 8px;
    text-align: center;
}

.powered-by {
    font-size: 11px;
    color: #999;
}

/* Loading Animation */
.typing-indicator {
    display: flex;
    gap: 4px;
    padding: 12px;
}

.typing-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #667eea;
    animation: typing 1.4s infinite;
}

.typing-dot:nth-child(2) {
    animation-delay: 0.2s;
}

.typing-dot:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes typing {
    0%, 60%, 100% {
        transform: translateY(0);
    }
    30% {
        transform: translateY(-10px);
    }
}

/* Scrollbar */
.rag-chat-messages::-webkit-scrollbar {
    width: 6px;
}

.rag-chat-messages::-webkit-scrollbar-track {
    background: transparent;
}

.rag-chat-messages::-webkit-scrollbar-thumb {
    background: #ccc;
    border-radius: 3px;
}

.rag-chat-messages::-webkit-scrollbar-thumb:hover {
    background: #999;
}
</style>

<script>
(function() {
    const sessionId = '{{ $sessionId }}';
    const container = document.getElementById(`rag-chat-${sessionId}`);
    const messagesDiv = document.getElementById(`messages-${sessionId}`);
    const inputField = document.getElementById(`input-${sessionId}`);
    const sendBtn = document.getElementById(`send-btn-${sessionId}`);
    const fileInput = document.getElementById(`file-input-${sessionId}`);
    const filePreview = document.getElementById(`file-preview-${sessionId}`);
    const fileUploadBtn = container.querySelector('.file-upload-btn');
    
    // Current attached file
    let attachedFile = null;
    
    // Configuration
    const config = {
        engine: container.dataset.engine,
        model: container.dataset.model,
        memory: container.dataset.memory === 'true',
        actions: container.dataset.actions === 'true',
        useIntelligentRAG: container.dataset.useIntelligentRag === 'true',
        ragCollections: JSON.parse(container.dataset.ragCollections || '[]'),
        sessionId: sessionId,
        showSources: {{ $showSources ? 'true' : 'false' }},
        showActions: {{ $showActions ? 'true' : 'false' }},
        showOptions: {{ $showOptions ? 'true' : 'false' }},
    };
    
    // Send message function
    window[`sendMessage${sessionId}`] = async function() {
        const message = inputField.value.trim();
        if (!message) return;
        
        // Add user message
        addMessage(message, 'user');
        inputField.value = '';
        inputField.style.height = 'auto';
        
        // Show typing indicator
        const typingId = showTyping();
        
        // Disable send button
        sendBtn.disabled = true;
        
        try {
            const response = await fetch('/api/v1/rag/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    message: message,
                    session_id: config.sessionId,
                    engine: config.engine,
                    model: config.model,
                    memory: config.memory,
                    actions: config.actions,
                    use_intelligent_rag: config.useIntelligentRAG,
                    rag_collections: config.ragCollections
                })
            });
            
            const data = await response.json();
            
            // Remove typing indicator
            removeTyping(typingId);
            
            if (data.success) {
                // Add assistant message with RAG features
                addAssistantMessage(data.data);
            } else {
                addMessage('Sorry, there was an error processing your request.', 'assistant');
            }
        } catch (error) {
            removeTyping(typingId);
            addMessage('Sorry, there was an error connecting to the server.', 'assistant');
            console.error('Chat error:', error);
        } finally {
            sendBtn.disabled = false;
            inputField.focus();
        }
    };
    
    // File upload handler
    fileInput.addEventListener('change', async function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        // Validate file size (max 10MB)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size must be less than 10MB');
            fileInput.value = '';
            return;
        }
        
        // Show file preview
        attachedFile = file;
        showFilePreview(file);
    });
    
    // Show file preview
    function showFilePreview(file) {
        const isImage = file.type.startsWith('image/');
        
        let previewHTML = '';
        if (isImage) {
            const reader = new FileReader();
            reader.onload = function(e) {
                filePreview.innerHTML = `
                    <img src="${e.target.result}" class="file-thumbnail" alt="Preview">
                    <span class="file-name">${file.name}</span>
                    <button class="file-remove" onclick="removeFile${sessionId}()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                    </button>
                `;
                filePreview.classList.remove('hidden');
            };
            reader.readAsDataURL(file);
        } else {
            const icon = getFileIcon(file.type);
            filePreview.innerHTML = `
                <span class="file-icon">${icon}</span>
                <span class="file-name">${file.name}</span>
                <button class="file-remove" onclick="removeFile${sessionId}()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            `;
            filePreview.classList.remove('hidden');
        }
    }
    
    // Get file icon based on type
    function getFileIcon(mimeType) {
        if (mimeType.includes('pdf')) return '📄';
        if (mimeType.includes('word') || mimeType.includes('document')) return '📝';
        if (mimeType.includes('text')) return '📃';
        return '📎';
    }
    
    // Remove attached file
    window[`removeFile${sessionId}`] = function() {
        attachedFile = null;
        fileInput.value = '';
        filePreview.classList.add('hidden');
        filePreview.innerHTML = '';
    };
    
    // Send message with file
    async function sendMessageWithFile(message, file) {
        const formData = new FormData();
        formData.append('file', file);
        formData.append('message', message || 'Analyze this file');
        formData.append('session_id', config.sessionId);
        formData.append('engine', config.engine);
        formData.append('model', config.model);
        formData.append('memory', config.memory);
        formData.append('use_intelligent_rag', config.useIntelligentRAG);
        formData.append('rag_collections', JSON.stringify(config.ragCollections));
        
        const response = await fetch('/api/v1/rag/analyze-file', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            body: formData
        });
        
        return await response.json();
    }
    
    // Override send message to handle file attachments
    const originalSendMessage = window[`sendMessage${sessionId}`];
    window[`sendMessage${sessionId}`] = async function() {
        const message = inputField.value.trim();
        
        // If there's an attached file, send with file
        if (attachedFile) {
            const file = attachedFile;
            const displayMessage = message || `📎 Analyzing: ${file.name}`;
            
            // Add user message
            addMessage(displayMessage, 'user');
            inputField.value = '';
            inputField.style.height = 'auto';
            
            // Clear file preview
            window[`removeFile${sessionId}`]();
            
            // Show typing indicator
            const typingId = showTyping();
            sendBtn.disabled = true;
            
            try {
                const data = await sendMessageWithFile(message, file);
                removeTyping(typingId);
                
                if (data.success) {
                    addAssistantMessage(data.data);
                } else {
                    addMessage(data.error || 'Sorry, there was an error analyzing the file.', 'assistant');
                }
            } catch (error) {
                removeTyping(typingId);
                addMessage('Sorry, there was an error uploading the file.', 'assistant');
                console.error('File upload error:', error);
            } finally {
                sendBtn.disabled = false;
                inputField.focus();
            }
            return;
        }
        
        // No file, use original send message
        if (!message) return;
        originalSendMessage();
    };
    
    // Add user/assistant message
    function addMessage(content, role) {
        const messageDiv = document.createElement('div');
        messageDiv.className = `message ${role}`;
        
        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.innerHTML = role === 'user' ? 
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>' :
            '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        contentDiv.textContent = content;
        
        messageDiv.appendChild(avatar);
        messageDiv.appendChild(contentDiv);
        messagesDiv.appendChild(messageDiv);
        
        scrollToBottom();
    }
    
    // Add assistant message with RAG features
    function addAssistantMessage(data) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'message assistant';
        
        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        
        // Debug: Log numbered options
        if (data.numbered_options && data.numbered_options.length > 0) {
            console.log('📋 Numbered options detected:', data.numbered_options.length);
            console.log(data.numbered_options);
        }
        
        // Add response text (remove numbered options from text)
        const responseDiv = document.createElement('div');
        const cleanText = removeNumberedOptions(data.response, data.numbered_options || []);
        responseDiv.innerHTML = escapeHtml(cleanText).replace(/\n/g, '<br>');
        contentDiv.appendChild(responseDiv);
        
        // Add option cards
        if (data.numbered_options && data.numbered_options.length > 0) {
            console.log('🎴 Creating option cards...');
            const optionCards = createOptionCards(data.numbered_options);
            if (optionCards) {
                console.log('✅ Option cards created successfully');
                contentDiv.appendChild(optionCards);
            } else {
                console.log('❌ Failed to create option cards');
            }
        } else {
            console.log('ℹ️ No numbered options in response');
        }
        
        // Add RAG sources
        if (config.showSources && data.rag_enabled && data.sources && data.sources.length > 0) {
            contentDiv.appendChild(createSourcesDisplay(data.sources));
        }
        
        // Add action buttons
        if (config.showActions && data.actions && data.actions.length > 0) {
            contentDiv.appendChild(createActionButtons(data.actions));
        }
        
        messageDiv.appendChild(avatar);
        messageDiv.appendChild(contentDiv);
        messagesDiv.appendChild(messageDiv);
        
        scrollToBottom();
    }
    
    // Create option cards
    function createOptionCards(options) {
        if (!config.showOptions || !options || options.length === 0) {
            return null;
        }
        
        const container = document.createElement('div');
        container.className = 'options-container';
        
        options.forEach(option => {
            const card = document.createElement('div');
            card.className = 'option-card';
            card.onclick = () => selectOption(option.value);
            
            card.innerHTML = `
                <div class="option-number">${option.number}</div>
                <p class="option-text">${escapeHtml(option.text)}</p>
                <div class="option-icon">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <polyline points="9 18 15 12 9 6"/>
                    </svg>
                </div>
            `;
            
            container.appendChild(card);
        });
        
        return container;
    }
    
    // Remove numbered options from text
    function removeNumberedOptions(text, options) {
        if (!options || options.length === 0) {
            console.log('⚠️ No options to remove');
            return text;
        }
        
        console.log('🔧 Removing options from text:', options.length);
        let cleanText = text;
        
        options.forEach(option => {
            console.log(`  Removing option ${option.number}: "${option.text}"`);
            
            // Remove the entire line that starts with "number. "
            const linePattern = new RegExp(
                `^${option.number}\\.\\s+.+?(?=\\n\\n|\\n(?=\\d+\\.)|$)`,
                'gms'
            );
            
            const beforeLength = cleanText.length;
            cleanText = cleanText.replace(linePattern, '');
            const afterLength = cleanText.length;
            
            if (beforeLength !== afterLength) {
                console.log(`    ✅ Removed ${beforeLength - afterLength} characters`);
            } else {
                console.log(`    ⚠️ Pattern didn't match`);
            }
        });
        
        // Clean up extra newlines
        cleanText = cleanText.replace(/\n{3,}/g, '\n\n').trim();
        
        console.log('✅ Text cleaned, remaining length:', cleanText.length);
        return cleanText;
    }
    
    // Escape regex special characters
    function escapeRegex(str) {
        return str.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
    
    // Create sources display
    function createSourcesDisplay(sources) {
        const sourcesDiv = document.createElement('div');
        sourcesDiv.className = 'rag-sources';
        
        const header = document.createElement('div');
        header.className = 'sources-header';
        header.innerHTML = `
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
            </svg>
            Sources (${sources.length})
        `;
        sourcesDiv.appendChild(header);
        
        sources.forEach(source => {
            const sourceItem = document.createElement('div');
            sourceItem.className = 'source-item';
            sourceItem.onclick = () => viewSource(source);
            sourceItem.innerHTML = `
                <div class="source-title">${escapeHtml(source.title)}</div>
                <div class="source-meta">
                    <span class="source-relevance">${source.relevance}%</span>
                    <span>${source.model_type} #${source.model_id}</span>
                </div>
            `;
            sourcesDiv.appendChild(sourceItem);
        });
        
        return sourcesDiv;
    }
    
    // Create action buttons
    function createActionButtons(actions) {
        const actionsDiv = document.createElement('div');
        actionsDiv.className = 'action-buttons';
        
        actions.forEach(action => {
            const btn = document.createElement('button');
            btn.className = 'action-btn';
            btn.textContent = action.label;
            btn.onclick = () => executeAction(action);
            actionsDiv.appendChild(btn);
        });
        
        return actionsDiv;
    }
    
    // Select numbered option
    function selectOption(value) {
        inputField.value = value;
        window[`sendMessage${sessionId}`]();
    }
    
    // Also expose globally for backwards compatibility
    window[`selectOption${sessionId}`] = selectOption;
    
    // View source
    function viewSource(source) {
        const url = `/${source.model_type.toLowerCase()}s/${source.model_id}`;
        window.open(url, '_blank');
    }
    
    // Execute action
    async function executeAction(action) {
        try {
            const response = await fetch('/api/v1/rag/actions/execute', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({
                    action_id: action.id,
                    action_type: action.type,
                    payload: action.data
                })
            });
            
            const data = await response.json();
            
            if (data.success && data.data.url) {
                window.open(data.data.url, '_blank');
            }
        } catch (error) {
            console.error('Action error:', error);
        }
    }
    
    // Show typing indicator
    function showTyping() {
        const typingDiv = document.createElement('div');
        typingDiv.className = 'message assistant';
        typingDiv.id = `typing-${Date.now()}`;
        
        const avatar = document.createElement('div');
        avatar.className = 'message-avatar';
        avatar.innerHTML = '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>';
        
        const contentDiv = document.createElement('div');
        contentDiv.className = 'message-content';
        contentDiv.innerHTML = '<div class="typing-indicator"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>';
        
        typingDiv.appendChild(avatar);
        typingDiv.appendChild(contentDiv);
        messagesDiv.appendChild(typingDiv);
        
        scrollToBottom();
        return typingDiv.id;
    }
    
    // Remove typing indicator
    function removeTyping(id) {
        const typingDiv = document.getElementById(id);
        if (typingDiv) {
            typingDiv.remove();
        }
    }
    
    // Clear chat
    window[`clearChat${sessionId}`] = function() {
        if (confirm('Are you sure you want to clear the chat history?')) {
            messagesDiv.innerHTML = '';
            addMessage('Chat history cleared. How can I help you?', 'assistant');
        }
    };
    
    // Handle keyboard shortcuts
    window[`handleKeyDown${sessionId}`] = function(event) {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            window[`sendMessage${sessionId}`]();
        }
    };
    
    // Auto-resize textarea
    inputField.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });
    
    // Scroll to bottom
    function scrollToBottom() {
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }
    
    // Escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
})();
</script>
