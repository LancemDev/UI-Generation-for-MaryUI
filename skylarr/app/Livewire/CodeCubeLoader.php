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
        'updateFaceText' => 'updateFaceText',
        'projectSelected' => 'hideLoader'
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
    
    public function hideLoader()
    {
        // This will be handled by JavaScript to hide the loader
        $this->dispatch('hideLoader');
    }
    
    public function updateFaceText($faceIndex, $text)
    {
        if (isset($this->faceTexts[$faceIndex])) {
            $this->faceTexts[$faceIndex] = $text;
        }
    }
    
    private function generateCodeFiles()
    {
        // Read code files from separate files for better organization
        $htmlFile = file_get_contents(resource_path('views/code-samples/index.html'));
        $cssFile = file_get_contents(resource_path('views/code-samples/styles.css'));
        $jsFile = file_get_contents(resource_path('views/code-samples/main.js'));

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