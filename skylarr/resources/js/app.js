import './bootstrap';

// Texts to display dynamically
const texts = ["Dynamic UIs ", "Livewire UIs ", "Laravel UIs ", "AI-Powered UIs ", "Beautiful UIs "];
let index = 0;
let charIndex = 0;
let isDeleting = false;
const typingSpeed = 100; // Typing speed in ms
const deletingSpeed = 50; // Deleting speed in ms
const delayBetweenTexts = 2000; // Delay before switching to the next text
const dynamicTextElement = document.getElementById("dynamic-text");

function typeText() {
    const currentText = texts[index];
    if (isDeleting) {
        // Remove characters
        dynamicTextElement.textContent = currentText.substring(0, charIndex--);
    } else {
        // Add characters
        dynamicTextElement.textContent = currentText.substring(0, charIndex++);
    }

    // Determine if typing or deleting is complete
    if (!isDeleting && charIndex === currentText.length) {
        isDeleting = true;
        setTimeout(typeText, delayBetweenTexts); // Pause before deleting
    } else if (isDeleting && charIndex === 0) {
        isDeleting = false;
        index = (index + 1) % texts.length; // Move to the next text
        setTimeout(typeText, typingSpeed);
    } else {
        setTimeout(typeText, isDeleting ? deletingSpeed : typingSpeed);
    }
}

// Start the typing animation
typeText();
