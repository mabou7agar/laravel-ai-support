/**
 * Async Chat with SSE Support - Frontend Example
 * 
 * This example shows how to use the async workflow feature with SSE streaming
 * alongside the existing synchronous chat functionality.
 */

class AsyncChatClient {
    constructor(apiBaseUrl, authToken) {
        this.apiBaseUrl = apiBaseUrl;
        this.authToken = authToken;
        this.sessionId = 'session-' + Date.now();
        this.eventSource = null;
    }

    /**
     * Get auth headers
     */
    getAuthHeaders() {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'Authorization': `Bearer ${this.authToken}`,
        };
    }

    /**
     * Send message with optional async mode
     * 
     * @param {string} message - User message
     * @param {object} options - Chat options
     * @param {boolean} options.async - Enable async mode (default: false)
     * @param {boolean} options.actions - Enable actions (default: true)
     * @param {boolean} options.memory - Enable memory (default: true)
     * @param {boolean} options.intelligent_rag - Enable RAG (default: true)
     */
    async sendMessage(message, options = {}) {
        const {
            async = false,
            actions = true,
            memory = true,
            intelligent_rag = true,
        } = options;

        const response = await fetch(`${this.apiBaseUrl}/chat/send`, {
            method: 'POST',
            headers: this.getAuthHeaders(),
            body: JSON.stringify({
                message,
                session_id: this.sessionId,
                async,
                actions,
                memory,
                intelligent_rag,
            }),
        });

        const data = await response.json();

        if (data.async) {
            // Async mode: Connect to SSE stream
            return this.handleAsyncResponse(data);
        } else {
            // Sync mode: Return response immediately
            return {
                success: data.success,
                response: data.response,
                actions: data.actions || [],
                sources: data.sources || [],
            };
        }
    }

    /**
     * Handle async response with SSE streaming
     */
    async handleAsyncResponse(data) {
        return new Promise((resolve, reject) => {
            // Close existing connection if any
            if (this.eventSource) {
                this.eventSource.close();
            }

            // Connect to SSE stream
            this.eventSource = new EventSource(data.stream_url);

            this.eventSource.onmessage = (event) => {
                const update = JSON.parse(event.data);

                // Emit progress event
                this.onProgress?.(update);

                if (update.status === 'completed') {
                    this.eventSource.close();
                    resolve({
                        success: true,
                        response: update.response,
                        actions: update.actions || [],
                        sources: update.sources || [],
                        metadata: update.metadata || {},
                    });
                } else if (update.status === 'failed') {
                    this.eventSource.close();
                    reject(new Error(update.error || 'Workflow failed'));
                } else if (update.status === 'timeout') {
                    this.eventSource.close();
                    reject(new Error('Request timed out'));
                }
            };

            this.eventSource.onerror = (error) => {
                this.eventSource.close();
                reject(new Error('SSE connection failed'));
            };
        });
    }

    /**
     * Set progress callback
     */
    onProgress(callback) {
        this.onProgress = callback;
    }

    /**
     * Disconnect SSE stream
     */
    disconnect() {
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
    }
}

// ============================================================================
// Usage Examples
// ============================================================================

// Example 1: Synchronous mode (default - existing behavior)
const chat = new AsyncChatClient('https://dash.test/ai-demo', 'your-bearer-token');

async function sendSyncMessage() {
    try {
        const result = await chat.sendMessage('Hello!', { async: false });
        console.log('Response:', result.response);
    } catch (error) {
        console.error('Error:', error);
    }
}

// Example 2: Async mode with SSE streaming (new feature)
async function sendAsyncMessage() {
    try {
        // Set progress callback
        chat.onProgress = (update) => {
            console.log('Progress:', update.message, update.progress + '%');
            // Update UI with progress
            updateLoadingIndicator(update.message, update.progress);
        };

        const result = await chat.sendMessage('create invoice', { async: true });
        console.log('Final response:', result.response);
        displayMessage(result.response);
    } catch (error) {
        console.error('Error:', error);
        displayError(error.message);
    }
}

// Example 3: Smart mode - auto-detect when to use async
async function sendSmartMessage(message) {
    // Use async for workflow-heavy requests
    const isWorkflowRequest = /create|add|make|new/i.test(message);
    
    try {
        if (isWorkflowRequest) {
            console.log('Using async mode for workflow request...');
            chat.onProgress = (update) => {
                updateLoadingIndicator(update.message, update.progress);
            };
        }

        const result = await chat.sendMessage(message, { 
            async: isWorkflowRequest 
        });
        
        displayMessage(result.response);
    } catch (error) {
        displayError(error.message);
    }
}

// ============================================================================
// UI Helper Functions (implement based on your frontend framework)
// ============================================================================

function updateLoadingIndicator(message, progress) {
    // Update your loading UI
    // Example: document.querySelector('.loading-message').textContent = message;
    // Example: document.querySelector('.progress-bar').style.width = progress + '%';
}

function displayMessage(message) {
    // Display the final message in your chat UI
    // Example: appendMessageToChat('assistant', message);
}

function displayError(error) {
    // Display error in your chat UI
    // Example: appendMessageToChat('system', 'Error: ' + error);
}

// ============================================================================
// Export for use in other modules
// ============================================================================

if (typeof module !== 'undefined' && module.exports) {
    module.exports = AsyncChatClient;
}
