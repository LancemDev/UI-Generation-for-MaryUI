// Particle System
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
                    this.ctx.strokeStyle = `rgba(0, 255, 255, ${0.2 * (1 - dist/100)})`;
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
});
