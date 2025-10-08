<div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/95 backdrop-blur-sm" style="display: none;">
    <div class="w-full h-full flex flex-col items-center justify-center gap-10 p-8">
        {{-- Input Section --}}
        <div class="flex gap-4 p-6 bg-white/5 rounded-lg border border-white/10 backdrop-blur-sm">
            <input 
                type="text" 
                placeholder="e.g. 'Build me a portfolio website'"
                wire:model="prompt"
                class="px-4 py-3 text-base bg-white/10 border border-white/20 rounded text-white placeholder-white/40 w-80 font-mono focus:outline-none focus:border-cyan-400 focus:ring-1 focus:ring-cyan-400"
                disabled="{{ $isGenerating }}"
            />
            <button 
                wire:click="startGeneration"
                class="px-6 py-3 text-base font-mono rounded border transition-all duration-300
                    {{ $isGenerating 
                        ? 'bg-gray-600/30 border-gray-500 text-gray-400 cursor-not-allowed' 
                        : 'bg-cyan-500/20 border-cyan-400 text-cyan-400 hover:bg-cyan-500/30 hover:shadow-lg hover:shadow-cyan-400/25' 
                    }}"
                disabled="{{ $isGenerating }}"
            >
                {{ $isGenerating ? 'Generating...' : 'Generate Code' }}
            </button>
        </div>

        {{-- Code Cube --}}
        <div class="relative w-48 h-48" style="transform-style: preserve-3d; animation: rotate 10s infinite linear;">
            {{-- Front Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border font-mono"
                 style="transform: translateZ(100px); color: #0ff; background: rgba(0, 255, 255, 0.05); border-color: rgba(0, 255, 255, 0.3);">
                <div class="text-xs font-bold mb-1 opacity-80 border-b border-white/10 pb-1" style="color: #0ff;">
                    index.html
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0" style="color: #0ff;">
                    {{ substr($faceTexts[0] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Right Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border font-mono"
                 style="transform: rotateY(90deg) translateZ(100px); color: #f0f; background: rgba(255, 0, 255, 0.05); border-color: rgba(255, 0, 255, 0.3);">
                <div class="text-xs font-bold mb-1 opacity-80 border-b border-white/10 pb-1" style="color: #f0f;">
                    styles.css
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0" style="color: #f0f;">
                    {{ substr($faceTexts[1] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Top Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border font-mono"
                 style="transform: rotateX(90deg) translateZ(100px); color: #ff0; background: rgba(255, 255, 0, 0.05); border-color: rgba(255, 255, 0, 0.3);">
                <div class="text-xs font-bold mb-1 opacity-80 border-b border-white/10 pb-1" style="color: #ff0;">
                    main.js
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0" style="color: #ff0;">
                    {{ substr($faceTexts[2] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Back Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border font-mono"
                 style="transform: rotateY(180deg) translateZ(100px); color: #0f0; background: rgba(0, 255, 0, 0.05); border-color: rgba(0, 255, 0, 0.3);">
                <div class="text-xs font-bold mb-1 opacity-80 border-b border-white/10 pb-1" style="color: #0f0;">
                    index.html
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0" style="color: #0f0;">
                    {{ substr($faceTexts[3] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Left Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border font-mono"
                 style="transform: rotateY(-90deg) translateZ(100px); color: #f80; background: rgba(255, 136, 0, 0.05); border-color: rgba(255, 136, 0, 0.3);">
                <div class="text-xs font-bold mb-1 opacity-80 border-b border-white/10 pb-1" style="color: #f80;">
                    styles.css
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0" style="color: #f80;">
                    {{ substr($faceTexts[4] ?? '', 0, 140) }}
                </div>
            </div>

            {{-- Bottom Face --}}
            <div class="absolute w-48 h-48 flex flex-col text-xs leading-tight overflow-hidden break-all p-2.5 box-border border font-mono"
                 style="transform: rotateX(-90deg) translateZ(100px); color: #88f; background: rgba(136, 136, 255, 0.05); border-color: rgba(136, 136, 255, 0.3);">
                <div class="text-xs font-bold mb-1 opacity-80 border-b border-white/10 pb-1" style="color: #88f;">
                    main.js
                </div>
                <div class="flex-1 overflow-hidden whitespace-pre-wrap break-words m-0" style="color: #88f;">
                    {{ substr($faceTexts[5] ?? '', 0, 140) }}
                </div>
            </div>
        </div>

        {{-- Status Text --}}
        @if($isGenerating)
            <div class="text-center">
                <div class="text-cyan-400 font-mono text-lg mb-2">Generating Code...</div>
                <div class="flex items-center justify-center gap-2">
                    <div class="w-2 h-2 bg-cyan-400 rounded-full animate-pulse"></div>
                    <div class="w-2 h-2 bg-cyan-400 rounded-full animate-pulse" style="animation-delay: 0.2s;"></div>
                    <div class="w-2 h-2 bg-cyan-400 rounded-full animate-pulse" style="animation-delay: 0.4s;"></div>
                </div>
            </div>
        @endif
    </div>

    {{-- CSS Animations --}}
    <style>
        @keyframes rotate {
            from {
                transform: rotateX(-20deg) rotateY(30deg);
            }
            to {
                transform: rotateX(-20deg) rotateY(390deg);
            }
        }

        input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        button:hover:not(:disabled) {
            background: rgba(0, 255, 255, 0.3) !important;
            box-shadow: 0 0 20px rgba(0, 255, 255, 0.5);
        }
    </style>

    {{-- JavaScript for Real-time Streaming --}}
    <script>
        document.addEventListener('livewire:init', () => {
            let streamingIntervals = [];
            const loaderElement = document.querySelector('.fixed.inset-0.z-50');

            // Show loader
            Livewire.on('showCodeCubeLoader', () => {
                if (loaderElement) {
                    loaderElement.style.display = 'flex';
                }
            });

            // Hide loader
            Livewire.on('hideCodeCubeLoader', () => {
                if (loaderElement) {
                    loaderElement.style.display = 'none';
                }
                // Clear any running intervals
                streamingIntervals.forEach(clearInterval);
                streamingIntervals = [];
            });

            Livewire.on('startGeneration', () => {
                // Clear any existing intervals
                streamingIntervals.forEach(clearInterval);
                streamingIntervals = [];

                // Sample code files (same as PHP)
                const codeFiles = [
                    `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interactive Portfolio</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <nav class="navbar">
        <div class="logo">
            <span>&lt;dev/&gt;</span>
        </div>
        <ul class="nav-menu">
            <li><a href="#home">Home</a></li>
            <li><a href="#projects">Projects</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
    </nav>

    <section class="hero">
        <canvas id="particles"></canvas>
        <div class="hero-content">
            <h1 class="glitch" data-text="Hello World">Hello World</h1>
            <p class="typing">I'm a <span id="role"></span></p>
            <button class="cta">View My Work</button>
        </div>
    </section>

    <section id="projects" class="projects">
        <h2>Featured Projects</h2>
        <div class="project-grid">
            <div class="project-card">
                <img src="project1.jpg" alt="Project">
                <h3>E-commerce Platform</h3>
                <p>Full-stack React application</p>
            </div>
        </div>
    </section>

    <script src="main.js"></script>
</body>
</html>`,

                    `* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #00ffff;
    --secondary: #ff00ff;
    --dark: #0a0a0a;
    --text: #ffffff;
}

body {
    font-family: 'Courier New', monospace;
    background: var(--dark);
    color: var(--text);
    overflow-x: hidden;
}

.navbar {
    position: fixed;
    width: 100%;
    padding: 1rem 2rem;
    background: rgba(10, 10, 10, 0.9);
    backdrop-filter: blur(10px);
    z-index: 1000;
}

.logo {
    font-size: 1.5rem;
    color: var(--primary);
    font-weight: bold;
}

.nav-menu {
    display: flex;
    list-style: none;
    gap: 2rem;
    float: right;
}

.nav-menu a {
    color: var(--text);
    text-decoration: none;
    transition: color 0.3s;
}

.nav-menu a:hover {
    color: var(--primary);
}

.hero {
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

#particles {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
}

.hero-content {
    text-align: center;
    z-index: 10;
}

.glitch {
    font-size: 4rem;
    font-weight: bold;
    text-transform: uppercase;
    position: relative;
    color: var(--text);
    letter-spacing: 5px;
    animation: glitch 2s infinite;
}

.glitch::before,
.glitch::after {
    content: attr(data-text);
    position: absolute;
    top: 0;
    left: 0;
}

.glitch::before {
    animation: glitch-1 0.5s infinite;
    color: var(--primary);
    z-index: -1;
}

.glitch::after {
    animation: glitch-2 0.5s infinite;
    color: var(--secondary);
    z-index: -2;
}

@keyframes glitch {
    0%, 100% { transform: translate(0); }
    20% { transform: translate(-2px, 2px); }
    40% { transform: translate(-2px, -2px); }
    60% { transform: translate(2px, 2px); }
    80% { transform: translate(2px, -2px); }
}

@keyframes glitch-1 {
    0%, 100% { clip: rect(0, 900px, 0, 0); }
    25% { clip: rect(0, 900px, 20px, 0); }
    50% { clip: rect(40px, 900px, 60px, 0); }
    75% { clip: rect(80px, 900px, 100px, 0); }
}

.cta {
    margin-top: 2rem;
    padding: 1rem 2rem;
    background: linear-gradient(45deg, var(--primary), var(--secondary));
    border: none;
    color: var(--dark);
    font-weight: bold;
    cursor: pointer;
    transition: transform 0.3s;
}

.cta:hover {
    transform: scale(1.05);
}`,

                    `// Particle System
class ParticleSystem {
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.particles = [];
        this.init();
    }

    init() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
        
        for (let i = 0; i < 100; i++) {
            this.particles.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height,
                vx: Math.random() * 2 - 1,
                vy: Math.random() * 2 - 1,
                size: Math.random() * 2 + 1
            });
        }
        this.animate();
    }

    animate() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        this.ctx.fillStyle = 'rgba(0, 255, 255, 0.5)';
        
        this.particles.forEach(p => {
            p.x += p.vx;
            p.y += p.vy;
            
            if (p.x < 0 || p.x > this.canvas.width) p.vx *= -1;
            if (p.y < 0 || p.y > this.canvas.height) p.vy *= -1;
            
            this.ctx.beginPath();
            this.ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            this.ctx.fill();
        });
        
        // Draw connections
        this.particles.forEach((p1, i) => {
            this.particles.slice(i + 1).forEach(p2 => {
                const dist = Math.hypot(p1.x - p2.x, p1.y - p2.y);
                if (dist < 100) {
                    this.ctx.strokeStyle = \`rgba(0, 255, 255, \${0.2 * (1 - dist/100)})\`;
                    this.ctx.beginPath();
                    this.ctx.moveTo(p1.x, p1.y);
                    this.ctx.lineTo(p2.x, p2.y);
                    this.ctx.stroke();
                }
            });
        });
        
        requestAnimationFrame(() => this.animate());
    }
}

// Typing Effect
class TypeWriter {
    constructor(element, words) {
        this.element = element;
        this.words = words;
        this.wordIndex = 0;
        this.charIndex = 0;
        this.isDeleting = false;
        this.type();
    }

    type() {
        const current = this.words[this.wordIndex % this.words.length];
        
        if (this.isDeleting) {
            this.element.textContent = current.substring(0, this.charIndex--);
        } else {
            this.element.textContent = current.substring(0, this.charIndex++);
        }
        
        let speed = this.isDeleting ? 50 : 100;
        
        if (!this.isDeleting && this.charIndex === current.length) {
            speed = 2000;
            this.isDeleting = true;
        } else if (this.isDeleting && this.charIndex === 0) {
            this.isDeleting = false;
            this.wordIndex++;
        }
        
        setTimeout(() => this.type(), speed);
    }
}

// Initialize when DOM loads
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('particles');
    if (canvas) new ParticleSystem(canvas);

    const role = document.getElementById('role');
    if (role) {
        new TypeWriter(role, ['Developer', 'Designer', 'Creator']);
    }

    // Smooth scroll
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', e => {
            e.preventDefault();
            const target = document.querySelector(anchor.getAttribute('href'));
            if (target) target.scrollIntoView({ behavior: 'smooth' });
        });
    });
});`,

                    // Repeat for remaining faces
                    `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Interactive Portfolio</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <nav class="navbar">
        <div class="logo">
            <span>&lt;dev/&gt;</span>
        </div>
        <ul class="nav-menu">
            <li><a href="#home">Home</a></li>
            <li><a href="#projects">Projects</a></li>
            <li><a href="#contact">Contact</a></li>
        </ul>
    </nav>

    <section class="hero">
        <canvas id="particles"></canvas>
        <div class="hero-content">
            <h1 class="glitch" data-text="Hello World">Hello World</h1>
            <p class="typing">I'm a <span id="role"></span></p>
            <button class="cta">View My Work</button>
        </div>
    </section>

    <section id="projects" class="projects">
        <h2>Featured Projects</h2>
        <div class="project-grid">
            <div class="project-card">
                <img src="project1.jpg" alt="Project">
                <h3>E-commerce Platform</h3>
                <p>Full-stack React application</p>
            </div>
        </div>
    </section>

    <script src="main.js"></script>
</body>
</html>`,

                    `* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

:root {
    --primary: #00ffff;
    --secondary: #ff00ff;
    --dark: #0a0a0a;
    --text: #ffffff;
}

body {
    font-family: 'Courier New', monospace;
    background: var(--dark);
    color: var(--text);
    overflow-x: hidden;
}

.navbar {
    position: fixed;
    width: 100%;
    padding: 1rem 2rem;
    background: rgba(10, 10, 10, 0.9);
    backdrop-filter: blur(10px);
    z-index: 1000;
}

.logo {
    font-size: 1.5rem;
    color: var(--primary);
    font-weight: bold;
}

.nav-menu {
    display: flex;
    list-style: none;
    gap: 2rem;
    float: right;
}

.nav-menu a {
    color: var(--text);
    text-decoration: none;
    transition: color 0.3s;
}

.nav-menu a:hover {
    color: var(--primary);
}

.hero {
    height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}

#particles {
    position: absolute;
    width: 100%;
    height: 100%;
    top: 0;
    left: 0;
}

.hero-content {
    text-align: center;
    z-index: 10;
}

.glitch {
    font-size: 4rem;
    font-weight: bold;
    text-transform: uppercase;
    position: relative;
    color: var(--text);
    letter-spacing: 5px;
    animation: glitch 2s infinite;
}

.glitch::before,
.glitch::after {
    content: attr(data-text);
    position: absolute;
    top: 0;
    left: 0;
}

.glitch::before {
    animation: glitch-1 0.5s infinite;
    color: var(--primary);
    z-index: -1;
}

.glitch::after {
    animation: glitch-2 0.5s infinite;
    color: var(--secondary);
    z-index: -2;
}

@keyframes glitch {
    0%, 100% { transform: translate(0); }
    20% { transform: translate(-2px, 2px); }
    40% { transform: translate(-2px, -2px); }
    60% { transform: translate(2px, 2px); }
    80% { transform: translate(2px, -2px); }
}

@keyframes glitch-1 {
    0%, 100% { clip: rect(0, 900px, 0, 0); }
    25% { clip: rect(0, 900px, 20px, 0); }
    50% { clip: rect(40px, 900px, 60px, 0); }
    75% { clip: rect(80px, 900px, 100px, 0); }
}

.cta {
    margin-top: 2rem;
    padding: 1rem 2rem;
    background: linear-gradient(45deg, var(--primary), var(--secondary));
    border: none;
    color: var(--dark);
    font-weight: bold;
    cursor: pointer;
    transition: transform 0.3s;
}

.cta:hover {
    transform: scale(1.05);
}`,

                    `// Particle System
class ParticleSystem {
    constructor(canvas) {
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
        this.particles = [];
        this.init();
    }

    init() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
        
        for (let i = 0; i < 100; i++) {
            this.particles.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height,
                vx: Math.random() * 2 - 1,
                vy: Math.random() * 2 - 1,
                size: Math.random() * 2 + 1
            });
        }
        this.animate();
    }

    animate() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        this.ctx.fillStyle = 'rgba(0, 255, 255, 0.5)';
        
        this.particles.forEach(p => {
            p.x += p.vx;
            p.y += p.vy;
            
            if (p.x < 0 || p.x > this.canvas.width) p.vx *= -1;
            if (p.y < 0 || p.y > this.canvas.height) p.vy *= -1;
            
            this.ctx.beginPath();
            this.ctx.arc(p.x, p.y, p.size, 0, Math.PI * 2);
            this.ctx.fill();
        });
        
        // Draw connections
        this.particles.forEach((p1, i) => {
            this.particles.slice(i + 1).forEach(p2 => {
                const dist = Math.hypot(p1.x - p2.x, p1.y - p2.y);
                if (dist < 100) {
                    this.ctx.strokeStyle = \`rgba(0, 255, 255, \${0.2 * (1 - dist/100)})\`;
                    this.ctx.beginPath();
                    this.ctx.moveTo(p1.x, p1.y);
                    this.ctx.lineTo(p2.x, p2.y);
                    this.ctx.stroke();
                }
            });
        });
        
        requestAnimationFrame(() => this.animate());
    }
}

// Typing Effect
class TypeWriter {
    constructor(element, words) {
        this.element = element;
        this.words = words;
        this.wordIndex = 0;
        this.charIndex = 0;
        this.isDeleting = false;
        this.type();
    }

    type() {
        const current = this.words[this.wordIndex % this.words.length];
        
        if (this.isDeleting) {
            this.element.textContent = current.substring(0, this.charIndex--);
        } else {
            this.element.textContent = current.substring(0, this.charIndex++);
        }
        
        let speed = this.isDeleting ? 50 : 100;
        
        if (!this.isDeleting && this.charIndex === current.length) {
            speed = 2000;
            this.isDeleting = true;
        } else if (this.isDeleting && this.charIndex === 0) {
            this.isDeleting = false;
            this.wordIndex++;
        }
        
        setTimeout(() => this.type(), speed);
    }
}

// Initialize when DOM loads
document.addEventListener('DOMContentLoaded', () => {
    const canvas = document.getElementById('particles');
    if (canvas) new ParticleSystem(canvas);

    const role = document.getElementById('role');
    if (role) {
        new TypeWriter(role, ['Developer', 'Designer', 'Creator']);
    }

    // Smooth scroll
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', e => {
            e.preventDefault();
            const target = document.querySelector(anchor.getAttribute('href'));
            if (target) target.scrollIntoView({ behavior: 'smooth' });
        });
    });
});`
                ];

                // Stream each file character by character
                codeFiles.forEach((file, faceIndex) => {
                    let charIndex = 0;
                    const maxChars = 140;
                    
                    const interval = setInterval(() => {
                        if (charIndex < file.length) {
                            const textToShow = file.substring(0, charIndex + 1).slice(-maxChars);
                            Livewire.dispatch('updateFaceText', { faceIndex, text: textToShow });
                            charIndex++;
                        } else {
                            clearInterval(interval);
                            streamingIntervals[faceIndex] = null;
                            
                            // Check if all faces are done streaming
                            if (streamingIntervals.every(int => int === null)) {
                                setTimeout(() => {
                                    Livewire.dispatch('stopGeneration');
                                }, 1000);
                            }
                        }
                    }, 20); // Fast streaming for code effect
                    
                    streamingIntervals[faceIndex] = interval;
                });
            });

            Livewire.on('stopGeneration', () => {
                streamingIntervals.forEach(clearInterval);
                streamingIntervals = [];
            });
        });
    </script>
</div>
