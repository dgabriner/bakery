<?php
define('ACCESS_ALLOWED', true);
require_once 'includes/config.php';
require_once 'includes/database.php';

$page_title = 'Call Headquarters';

require_once 'includes/header.php';
require_once 'includes/nav.php';
?>

<style>
.call-container {
    max-width: 600px;
    margin: 0 auto;
    padding: 40px 20px;
    text-align: center;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px;
    border-radius: 20px;
    margin-bottom: 40px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.page-header h1 {
    margin: 0 0 15px 0;
    font-size: 2.5rem;
    font-weight: 600;
}

.page-header p {
    margin: 0;
    font-size: 1.2rem;
    opacity: 0.9;
}

.headquarters-info {
    background: white;
    padding: 30px;
    border-radius: 15px;
    box-shadow: 0 6px 25px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.phone-number {
    font-size: 2.2rem;
    font-weight: bold;
    color: #2c3e50;
    margin: 20px 0 30px 0;
    letter-spacing: 2px;
    font-family: 'Courier New', monospace;
}

.call-options {
    margin: 20px 0;
}

.call-option-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 15px;
    max-width: 600px;
    margin: 0 auto;
}

.call-button {
    color: white;
    border: none;
    padding: 18px 25px;
    font-size: 1.1rem;
    font-weight: 600;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.3s ease;
    text-decoration: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    flex-direction: column;
    min-height: 80px;
}

.call-button.phone-call {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    box-shadow: 0 6px 20px rgba(40, 167, 69, 0.3);
}

.call-button.video-call {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    box-shadow: 0 6px 20px rgba(0, 123, 255, 0.3);
}

.call-button.voice-call {
    background: linear-gradient(135deg, #6f42c1 0%, #5a32a3 100%);
    box-shadow: 0 6px 20px rgba(111, 66, 193, 0.3);
}

.call-button:hover {
    transform: translateY(-3px);
    color: white;
    text-decoration: none;
}

.call-button.phone-call:hover {
    box-shadow: 0 10px 30px rgba(40, 167, 69, 0.4);
    background: linear-gradient(135deg, #218838 0%, #1aa882 100%);
}

.call-button.video-call:hover {
    box-shadow: 0 10px 30px rgba(0, 123, 255, 0.4);
    background: linear-gradient(135deg, #0056b3 0%, #004085 100%);
}

.call-button.voice-call:hover {
    box-shadow: 0 10px 30px rgba(111, 66, 193, 0.4);
    background: linear-gradient(135deg, #5a32a3 0%, #4c2a85 100%);
}

.call-button:active {
    transform: translateY(-1px);
}

.call-icon {
    font-size: 1.6rem;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); }
}

.contact-info {
    background: #f8f9fa;
    padding: 25px;
    border-radius: 12px;
    margin-top: 30px;
    border-left: 4px solid #007bff;
}

.contact-info h3 {
    margin: 0 0 15px 0;
    color: #2c3e50;
    font-size: 1.3rem;
}

.contact-detail {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin: 10px 0;
    font-size: 1rem;
    color: #495057;
}

.detail-icon {
    font-size: 1.2rem;
    color: #007bff;
    width: 20px;
    text-align: center;
}

.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-top: 30px;
}

.action-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    transition: all 0.3s ease;
    border: 2px solid transparent;
}

.action-card:hover {
    transform: translateY(-3px);
    border-color: #007bff;
    box-shadow: 0 8px 25px rgba(0,123,255,0.15);
}

.action-card h4 {
    margin: 0 0 10px 0;
    color: #2c3e50;
    font-size: 1.1rem;
}

.action-card p {
    margin: 0;
    color: #6c757d;
    font-size: 0.9rem;
    line-height: 1.4;
}

.device-note {
    background: #e8f4f8;
    border: 1px solid #bee5eb;
    border-radius: 8px;
    padding: 15px;
    margin-top: 20px;
    font-size: 0.9rem;
    color: #0c5460;
}

.device-note strong {
    color: #0a4750;
}

@media (max-width: 768px) {
    .call-container {
        padding: 20px 15px;
    }
    
    .page-header {
        padding: 30px 20px;
    }
    
    .page-header h1 {
        font-size: 2rem;
    }
    
    .phone-number {
        font-size: 1.8rem;
        letter-spacing: 1px;
    }
    
    .call-button {
        padding: 18px 40px;
        font-size: 1.2rem;
    }
    
    .headquarters-info {
        padding: 25px 20px;
    }
    
    .quick-actions {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .phone-number {
        font-size: 1.5rem;
        word-break: break-all;
    }
    
    .call-button {
        padding: 15px 30px;
        font-size: 1.1rem;
        flex-direction: column;
        gap: 8px;
    }
    
    .contact-detail {
        flex-direction: column;
        gap: 5px;
        text-align: center;
    }
}

/* WebRTC Call Modal */
.call-modal {
    display: none;
    position: fixed;
    z-index: 2000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.8);
    backdrop-filter: blur(5px);
}

.call-modal-content {
    background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    margin: 5% auto;
    padding: 0;
    border-radius: 20px;
    width: 90%;
    max-width: 800px;
    height: 80vh;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
    overflow: hidden;
    position: relative;
}

.call-header {
    background: rgba(0,0,0,0.2);
    padding: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    color: white;
}

.call-title {
    font-size: 1.3rem;
    font-weight: 600;
    margin: 0;
}

.call-status {
    font-size: 0.9rem;
    opacity: 0.8;
    margin: 5px 0 0 0;
}

.close-call {
    background: #dc3545;
    color: white;
    border: none;
    padding: 10px 15px;
    border-radius: 8px;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
    transition: all 0.3s ease;
}

.close-call:hover {
    background: #c82333;
    transform: scale(1.05);
}

.call-content {
    padding: 30px;
    text-align: center;
    color: white;
    height: calc(100% - 80px);
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.call-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    margin-bottom: 20px;
    animation: pulse-call 2s infinite;
}

@keyframes pulse-call {
    0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(102, 126, 234, 0.7); }
    70% { transform: scale(1.05); box-shadow: 0 0 0 20px rgba(102, 126, 234, 0); }
    100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(102, 126, 234, 0); }
}

.call-info h3 {
    margin: 0 0 10px 0;
    font-size: 1.5rem;
}

.call-info p {
    margin: 0 0 30px 0;
    opacity: 0.8;
}

.call-controls {
    display: flex;
    gap: 20px;
    justify-content: center;
    margin-top: 30px;
}

.call-control-btn {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    transition: all 0.3s ease;
}

.call-control-btn.mute {
    background: #6c757d;
    color: white;
}

.call-control-btn.mute:hover {
    background: #5a6268;
}

.call-control-btn.mute.active {
    background: #dc3545;
}

.call-control-btn.video {
    background: #007bff;
    color: white;
}

.call-control-btn.video:hover {
    background: #0056b3;
}

.call-control-btn.video.active {
    background: #dc3545;
}

.call-control-btn.end {
    background: #dc3545;
    color: white;
}

.call-control-btn.end:hover {
    background: #c82333;
    transform: scale(1.1);
}

.webrtc-setup {
    background: #e8f4f8;
    border: 1px solid #bee5eb;
    border-radius: 12px;
    padding: 20px;
    margin-top: 30px;
    text-align: left;
}

.webrtc-setup h4 {
    margin: 0 0 15px 0;
    color: #0c5460;
    font-size: 1.1rem;
}

.webrtc-setup p {
    margin: 10px 0;
    color: #0c5460;
    font-size: 0.9rem;
    line-height: 1.4;
}

.webrtc-setup .step {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin: 10px 0;
}

.webrtc-setup .step-number {
    background: #007bff;
    color: white;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
    font-weight: bold;
    flex-shrink: 0;
    margin-top: 2px;
}

.jitsi-container {
    width: 100%;
    height: 400px;
    border-radius: 12px;
    overflow: hidden;
    margin: 20px 0;
}

#jitsi-meet {
    width: 100%;
    height: 100%;
    border: none;
}
</style>

<div class="call-container">
    <div class="page-header">
        <h1>📞 Call Headquarters</h1>
        <p>Quick and easy contact to our main office</p>
        
        <!-- Debug button -->
        <button onclick="testFunction()" style="background: #f39c12; color: white; border: none; padding: 8px 16px; border-radius: 4px; margin: 10px 0; cursor: pointer; font-size: 0.9rem;">
            🧪 Test Function (Debug)
        </button>
    </div>
    
    <div class="headquarters-info">
        <h2 style="margin: 0 0 10px 0; color: #2c3e50;">Headquarters Phone</h2>
        <div class="phone-number">(415) 509-1210</div>
        
        <div class="call-options">
            <div class="call-option-grid">
                <a href="tel:+14155091210" class="call-button phone-call">
                    <span class="call-icon">📞</span>
                    <span>Phone Call</span>
                </a>
                
                <button onclick="startVideoCall()" class="call-button video-call" id="videoCallBtn">
                    <span class="call-icon">📹</span>
                    <span>Video Call</span>
                </button>
                
                <button onclick="startVoiceCall()" class="call-button voice-call" id="voiceCallBtn">
                    <span class="call-icon">🎤</span>
                    <span>Voice Call</span>
                </button>
            </div>
        </div>
        
        <div class="device-note">
            <strong>Call Options:</strong><br>
            • <strong>Phone Call:</strong> Uses your device's phone app<br>
            • <strong>Video Call:</strong> Browser-based video calling<br>
            • <strong>Voice Call:</strong> Browser-based audio calling
        </div>
        
        <div class="webrtc-setup">
            <h4>🌐 Browser-Based Calling Features</h4>
            <p><strong>Video & Voice calls work directly in your browser - no apps needed!</strong></p>
            
            <div class="step">
                <span class="step-number">1</span>
                <span>Click "Video Call" or "Voice Call" to start a browser-based call</span>
            </div>
            <div class="step">
                <span class="step-number">2</span>
                <span>Allow camera/microphone permissions when prompted</span>
            </div>
            <div class="step">
                <span class="step-number">3</span>
                <span>Share the meeting room link with headquarters</span>
            </div>
            <div class="step">
                <span class="step-number">4</span>
                <span>Start your conversation with crystal-clear quality</span>
            </div>
            
            <p><strong>Benefits:</strong> No downloads, works on any device, secure encrypted calls, screen sharing available.</p>
        </div>
    </div>
    
    <div class="contact-info">
        <h3>📍 Contact Information</h3>
        <div class="contact-detail">
            <span class="detail-icon">🏢</span>
            <span>Bakery Headquarters</span>
        </div>
        <div class="contact-detail">
            <span class="detail-icon">📞</span>
            <span>(415) 509-1210</span>
        </div>
        <div class="contact-detail">
            <span class="detail-icon">🕒</span>
            <span>Available during business hours</span>
        </div>
    </div>
    
    <div class="quick-actions">
        <div class="action-card">
            <h4>🚨 Emergency Contact</h4>
            <p>For urgent delivery issues, production problems, or immediate assistance</p>
        </div>
        
        <div class="action-card">
            <h4>📋 General Support</h4>
            <p>Questions about orders, customer issues, or operational guidance</p>
        </div>
        
        <div class="action-card">
            <h4>📊 Reporting</h4>
            <p>Daily reports, route updates, or system-related inquiries</p>
        </div>
        
        <div class="action-card">
            <h4>💼 Administrative</h4>
            <p>HR matters, scheduling changes, or policy questions</p>
        </div>
    </div>
</div>

<!-- WebRTC Call Modal -->
<div id="callModal" class="call-modal">
    <div class="call-modal-content">
        <div class="call-header">
            <div>
                <h3 class="call-title" id="callTitle">Connecting to Headquarters...</h3>
                <p class="call-status" id="callStatus">Initializing call...</p>
            </div>
            <button class="close-call" onclick="endCall()">End Call</button>
        </div>
        <div class="call-content" id="callContent">
            <div class="call-avatar">🏢</div>
            <div class="call-info">
                <h3>Bakery Headquarters</h3>
                <p>(415) 509-1210</p>
                <p id="callInstructions">Setting up your call room...</p>
            </div>
            <div class="jitsi-container" id="jitsiContainer" style="display: none;">
                <div id="jitsi-meet"></div>
            </div>
        </div>
    </div>
</div>

<script>
let jitsiApi = null;
let currentCallType = null;

// Load Jitsi Meet API dynamically with error handling
function loadJitsiAPI() {
    return new Promise((resolve, reject) => {
        if (window.JitsiMeetExternalAPI) {
            resolve();
            return;
        }
        
        const script = document.createElement('script');
        script.src = 'https://meet.jit.si/external_api.js';
        script.onload = () => {
            console.log('Jitsi API loaded successfully');
            resolve();
        };
        script.onerror = (error) => {
            console.error('Failed to load Jitsi API:', error);
            reject(error);
        };
        document.head.appendChild(script);
    });
}

document.addEventListener('DOMContentLoaded', function() {
    console.log('DOM loaded, setting up call buttons');
    
    const callButtons = document.querySelectorAll('.call-button');
    console.log('Found', callButtons.length, 'call buttons');
    
    // Add click analytics/feedback for all call buttons
    callButtons.forEach(button => {
        console.log('Setting up button:', button.id || button.className);
        
        button.addEventListener('click', function() {
            console.log('Button clicked:', this.id || this.className);
            // Visual feedback
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = '';
            }, 150);
        });
        
        // Add keyboard support for accessibility
        button.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });
    
    // Add direct event listeners for debugging
    const videoBtn = document.getElementById('videoCallBtn');
    const voiceBtn = document.getElementById('voiceCallBtn');
    
    if (videoBtn) {
        console.log('Video call button found');
        videoBtn.addEventListener('click', function(e) {
            console.log('Video button clicked directly');
            e.preventDefault();
            startVideoCall();
        });
    } else {
        console.error('Video call button not found');
    }
    
    if (voiceBtn) {
        console.log('Voice call button found');
        voiceBtn.addEventListener('click', function(e) {
            console.log('Voice button clicked directly');
            e.preventDefault();
            startVoiceCall();
        });
    } else {
        console.error('Voice call button not found');
    }
});

async function startVideoCall() {
    console.log('Video call button clicked');
    currentCallType = 'video';
    
    try {
        await loadJitsiAPI();
        initializeCall(true, true); // video: true, audio: true
    } catch (error) {
        console.error('Error starting video call:', error);
        showSimpleCallInterface('video');
    }
}

async function startVoiceCall() {
    console.log('Voice call button clicked');
    currentCallType = 'voice';
    
    try {
        await loadJitsiAPI();
        initializeCall(false, true); // video: false, audio: true
    } catch (error) {
        console.error('Error starting voice call:', error);
        showSimpleCallInterface('voice');
    }
}

function showSimpleCallInterface(type) {
    // Fallback to a simple interface if Jitsi fails
    document.getElementById('callModal').style.display = 'block';
    
    const callTitle = document.getElementById('callTitle');
    const callStatus = document.getElementById('callStatus');
    const callInstructions = document.getElementById('callInstructions');
    
    callTitle.textContent = `${type === 'video' ? 'Video' : 'Voice'} Call to Headquarters`;
    callStatus.textContent = 'Alternative calling methods';
    
    const roomName = 'bakery-hq-' + Date.now();
    const meetingLink = `https://meet.jit.si/${roomName}`;
    
    callInstructions.innerHTML = `
        <strong>📞 Quick Call Options:</strong><br><br>
        
        <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; margin: 15px 0;">
            <strong>Option 1: Direct Phone Call</strong><br>
            <button onclick="window.open('tel:+14155091210')" style="background: #28a745; color: white; border: none; padding: 12px 20px; border-radius: 6px; cursor: pointer; margin: 10px 0; font-size: 1rem;">
                📞 Call (415) 509-1210
            </button>
        </div>
        
        <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; margin: 15px 0;">
            <strong>Option 2: Web Meeting</strong><br>
            Send this link to headquarters:<br>
            <div style="background: rgba(0,0,0,0.3); padding: 10px; border-radius: 4px; margin: 10px 0; font-family: monospace; word-break: break-all; font-size: 0.9rem;">
                ${meetingLink}
            </div>
            <button onclick="copyToClipboard('${meetingLink}')" style="background: #007bff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin: 5px;">
                📋 Copy Link
            </button>
            <button onclick="window.open('${meetingLink}', '_blank')" style="background: #6f42c1; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin: 5px;">
                🚀 Open Meeting
            </button>
        </div>
        
        <div style="background: rgba(255,255,255,0.1); padding: 15px; border-radius: 8px; margin: 15px 0;">
            <strong>Option 3: Other Platforms</strong><br>
            <button onclick="openWhatsApp()" style="background: #25D366; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin: 5px;">
                💬 WhatsApp
            </button>
            <button onclick="openFaceTime()" style="background: #007aff; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer; margin: 5px;">
                📹 FaceTime
            </button>
        </div>
    `;
}

function initializeCall(videoEnabled, audioEnabled) {
    console.log('Initializing call with video:', videoEnabled, 'audio:', audioEnabled);
    
    // Check if we have the Jitsi API
    if (!window.JitsiMeetExternalAPI) {
        console.error('Jitsi API not available');
        showSimpleCallInterface(videoEnabled ? 'video' : 'voice');
        return;
    }
    
    // Show the call modal
    document.getElementById('callModal').style.display = 'block';
    
    // Update UI
    const callTitle = document.getElementById('callTitle');
    const callStatus = document.getElementById('callStatus');
    const callInstructions = document.getElementById('callInstructions');
    
    callTitle.textContent = videoEnabled ? 'Video Call to Headquarters' : 'Voice Call to Headquarters';
    callStatus.textContent = 'Setting up secure connection...';
    callInstructions.innerHTML = `
        <strong>Your ${videoEnabled ? 'video' : 'voice'} call room is being prepared...</strong><br><br>
        📋 <strong>Next Steps:</strong><br>
        1. Copy the meeting link that will appear<br>
        2. Send it to headquarters via text/email<br>
        3. Wait for them to join the call<br><br>
        🔒 Your call is encrypted and secure
    `;
    
    // Generate a unique room name for this call
    const roomName = 'bakery-hq-call-' + Date.now();
    console.log('Generated room name:', roomName);
    
    // Configure Jitsi Meet
    const options = {
        roomName: roomName,
        width: '100%',
        height: '100%',
        parentNode: document.getElementById('jitsi-meet'),
        configOverwrite: {
            startWithAudioMuted: !audioEnabled,
            startWithVideoMuted: !videoEnabled,
            enableWelcomePage: false,
            enableClosePage: false,
            prejoinPageEnabled: false,
            disableInviteFunctions: false,
            startAudioOnly: !videoEnabled,
            enableLayerSuspension: true,
            hideConferenceTimer: false,
            enableNoAudioDetection: true,
            enableNoiseCancellation: true,
            disableAP: false,
            liveStreamingEnabled: false,
            recordingEnabled: false,
            fileRecordingsEnabled: false,
            localRecording: {
                enabled: false
            }
        },
        interfaceConfigOverwrite: {
            TOOLBAR_BUTTONS: videoEnabled ? [
                'microphone', 'camera', 'hangup', 'chat', 'settings',
                'raisehand', 'videoquality', 'filmstrip', 'invite',
                'feedback', 'stats', 'shortcuts', 'tileview', 'download'
            ] : [
                'microphone', 'hangup', 'chat', 'settings',
                'raisehand', 'invite', 'feedback', 'stats', 'shortcuts'
            ],
            SETTINGS_SECTIONS: ['devices', 'language', 'moderator', 'profile', 'calendar'],
            SHOW_JITSI_WATERMARK: false,
            SHOW_WATERMARK_FOR_GUESTS: false,
            SHOW_BRAND_WATERMARK: false,
            BRAND_WATERMARK_LINK: '',
            DEFAULT_BACKGROUND: '#2c3e50',
            DISABLE_VIDEO_BACKGROUND: false,
            INITIAL_TOOLBAR_TIMEOUT: 20000,
            TOOLBAR_TIMEOUT: 4000,
            TOOLBAR_ALWAYS_VISIBLE: false,
            DEFAULT_LOGO_URL: '',
            DEFAULT_WELCOME_PAGE_LOGO_URL: ''
        },
        userInfo: {
            displayName: 'Bakery Field Team'
        }
    };
    
    // Initialize Jitsi Meet
    jitsiApi = new JitsiMeetExternalAPI('meet.jit.si', options);
    
    // Set up event listeners
    jitsiApi.addEventListener('videoConferenceJoined', function(participant) {
        callStatus.textContent = 'Connected! Waiting for headquarters to join...';
        document.getElementById('jitsiContainer').style.display = 'block';
        
        // Generate shareable link
        const meetingLink = `https://meet.jit.si/${roomName}`;
        callInstructions.innerHTML = `
            <strong>✅ Your call room is ready!</strong><br><br>
            📋 <strong>Send this link to headquarters:</strong><br>
            <div style="background: rgba(255,255,255,0.1); padding: 10px; border-radius: 8px; margin: 10px 0; font-family: monospace; word-break: break-all;">
                ${meetingLink}
            </div>
            📱 Or call them at (415) 509-1210 and share the room name:<br>
            <strong>${roomName}</strong><br><br>
            🎯 They can join from any device by visiting the link above
        `;
        
        // Add copy link functionality
        const copyButton = document.createElement('button');
        copyButton.textContent = '📋 Copy Link';
        copyButton.style.cssText = `
            background: #007bff; color: white; border: none; padding: 8px 16px;
            border-radius: 6px; cursor: pointer; margin: 10px 5px; font-size: 0.9rem;
        `;
        copyButton.onclick = function() {
            navigator.clipboard.writeText(meetingLink).then(() => {
                copyButton.textContent = '✅ Copied!';
                setTimeout(() => {
                    copyButton.textContent = '📋 Copy Link';
                }, 2000);
            });
        };
        
        const phoneButton = document.createElement('button');
        phoneButton.textContent = '📞 Call HQ';
        phoneButton.style.cssText = `
            background: #28a745; color: white; border: none; padding: 8px 16px;
            border-radius: 6px; cursor: pointer; margin: 10px 5px; font-size: 0.9rem;
        `;
        phoneButton.onclick = function() {
            window.open('tel:+14155091210', '_self');
        };
        
        callInstructions.appendChild(document.createElement('br'));
        callInstructions.appendChild(copyButton);
        callInstructions.appendChild(phoneButton);
    });
    
    jitsiApi.addEventListener('participantJoined', function(participant) {
        callStatus.textContent = 'Headquarters has joined the call!';
        callInstructions.innerHTML = '<strong>🎉 Call in progress with headquarters</strong>';
    });
    
    jitsiApi.addEventListener('participantLeft', function(participant) {
        callStatus.textContent = 'Participant left the call';
    });
    
    jitsiApi.addEventListener('videoConferenceLeft', function() {
        endCall();
    });
    
    jitsiApi.addEventListener('readyToClose', function() {
        endCall();
    });
}

function endCall() {
    if (jitsiApi) {
        jitsiApi.dispose();
        jitsiApi = null;
    }
    
    // Hide the modal
    document.getElementById('callModal').style.display = 'none';
    
    // Reset the UI
    document.getElementById('jitsiContainer').style.display = 'none';
    document.getElementById('jitsi-meet').innerHTML = '';
    
    // Reset status
    document.getElementById('callTitle').textContent = 'Connecting to Headquarters...';
    document.getElementById('callStatus').textContent = 'Initializing call...';
    document.getElementById('callInstructions').textContent = 'Setting up your call room...';
    
    currentCallType = null;
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('callModal');
    if (event.target === modal) {
        endCall();
    }
}

// Handle browser navigation/close
window.addEventListener('beforeunload', function() {
    if (jitsiApi) {
        jitsiApi.dispose();
    }
});

// Utility functions
function copyToClipboard(text) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            console.log('Link copied to clipboard');
            // Visual feedback
            event.target.textContent = '✅ Copied!';
            setTimeout(() => {
                event.target.textContent = '📋 Copy Link';
            }, 2000);
        }).catch(err => {
            console.error('Failed to copy:', err);
            fallbackCopyTextToClipboard(text);
        });
    } else {
        fallbackCopyTextToClipboard(text);
    }
}

function fallbackCopyTextToClipboard(text) {
    const textArea = document.createElement("textarea");
    textArea.value = text;
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            event.target.textContent = '✅ Copied!';
            setTimeout(() => {
                event.target.textContent = '📋 Copy Link';
            }, 2000);
        }
    } catch (err) {
        console.error('Fallback: Oops, unable to copy', err);
        alert('Please manually copy this link: ' + text);
    }
    document.body.removeChild(textArea);
}

function openWhatsApp() {
    const phone = '+14155091210';
    const message = encodeURIComponent('Hi! I need to speak with headquarters. Can we have a quick call?');
    const whatsappUrl = `https://wa.me/${phone}?text=${message}`;
    window.open(whatsappUrl, '_blank');
}

function openFaceTime() {
    // FaceTime URL scheme (works on iOS/macOS)
    const phone = '+14155091210';
    const facetimeUrl = `facetime://${phone}`;
    
    try {
        window.location.href = facetimeUrl;
    } catch (error) {
        console.log('FaceTime not available, falling back to regular call');
        window.open(`tel:${phone}`, '_self');
    }
}

// Test function for debugging
function testFunction() {
    console.log('Test function called!');
    alert('Test function works! Check console for more details.');
    
    console.log('Available functions:');
    console.log('- startVideoCall:', typeof startVideoCall);
    console.log('- startVoiceCall:', typeof startVoiceCall);
    console.log('- showSimpleCallInterface:', typeof showSimpleCallInterface);
    console.log('- loadJitsiAPI:', typeof loadJitsiAPI);
    
    console.log('Button elements:');
    console.log('- videoCallBtn:', document.getElementById('videoCallBtn'));
    console.log('- voiceCallBtn:', document.getElementById('voiceCallBtn'));
    
    console.log('Modal element:');
    console.log('- callModal:', document.getElementById('callModal'));
}

// Add some debugging
console.log('Call headquarters script loaded');
console.log('Current protocol:', window.location.protocol);
console.log('Secure context:', window.isSecureContext);
</script>

<?php require_once 'includes/footer.php'; ?> 