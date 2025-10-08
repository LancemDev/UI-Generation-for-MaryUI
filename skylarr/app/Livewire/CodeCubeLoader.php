<?php

namespace App\Livewire;

use Livewire\Component;

class CodeCubeLoader extends Component
{
    public bool $isGenerating = false;
    public array $faceTexts = ['', '', '', '', '', ''];
    public string $prompt = '';
    
    protected $listeners = [
        'startGeneration' => 'startGeneration',
        'stopGeneration' => 'stopGeneration',
        'updateFaceText' => 'updateFaceText'
    ];
    
    public function mount()
    {
        $this->faceTexts = ['', '', '', '', '', ''];
    }
    
    public function startGeneration()
    {
        $this->isGenerating = true;
        $this->faceTexts = ['', '', '', '', '', ''];
        
        // Generate sample code files
        $codeFiles = $this->generateCodeFiles();
        
        // Start streaming each face
        foreach ($codeFiles as $index => $file) {
            $this->streamToFace($index, $file);
        }
    }
    
    public function stopGeneration()
    {
        $this->isGenerating = false;
    }
    
    public function updateFaceText($faceIndex, $text)
    {
        if (isset($this->faceTexts[$faceIndex])) {
            $this->faceTexts[$faceIndex] = $text;
        }
    }
    
    private function generateCodeFiles()
    {
        $htmlFile = `<!DOCTYPE html>
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
</html>`;

        $cssFile = `* {
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
}`;

        $jsFile = `// Particle System
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
});`;

        return [$htmlFile, $cssFile, $jsFile, $htmlFile, $cssFile, $jsFile];
    }
    
    private function streamToFace($faceIndex, $file)
    {
        // This would be handled by JavaScript for real-time streaming
        // For now, we'll just set the text
        $this->faceTexts[$faceIndex] = substr($file, 0, 140);
    }
    
    public function render()
    {
        return view('livewire.code-cube-loader');
    }
}
