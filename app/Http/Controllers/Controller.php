<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    public function wooSleepSeconds() {
        return (int) config('app.woo_sleep_seconds');
    }

    public function wooProductsPerBatch() {
        return (int) config('app.woo_products_per_batch');
    }

    public function wooDefaultDesc() {
        return (bool) config('app.woo_default_desc');
    }

    public function odooWooCompany() {
        // Cast to string, not int: this is a trading name ("Everything Beauty
        // GH"), and (int) silently rendered every one of them as 0.
        return (string) config('app.odoowoo_company');
    }

    public function odooSleepSeconds() {
        return (int) config('app.odoo_delay');
    }

    public function cutToEndOfLastSentence($text)
    {
        // Find the last occurrence of a period, question mark, or exclamation mark
        $lastSentenceEnd = max(strrpos($text, '.'), strrpos($text, '?'), strrpos($text, '!'));

        // If no valid sentence end is found, return the original text
        if ($lastSentenceEnd === false) {
            return $text;
        }

        // Cut the text to the end of the last sentence
        $cutText = substr($text, 0, $lastSentenceEnd + 1); // Include the sentence end punctuation

        return $cutText;
    }

    public function trimSentences($inputText)
    {
        // Split the input text into paragraphs
        $paragraphs = preg_split('/\n\s*\n/', $inputText);

        // Process each paragraph
        foreach ($paragraphs as &$paragraph) {
            // Split the paragraph into sentences
            $sentences = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', $paragraph);

            // Trim each sentence
            foreach ($sentences as &$sentence) {
                $sentence = trim($sentence);
            }

            // Join the sentences back into the paragraph
            $paragraph = implode(' ', $sentences);
        }

        // Join the paragraphs back into the text
        $cleanedText = implode("\n\n", $paragraphs);

        return $cleanedText;
    }

    public function truncateString($inputText, $limit = 250)
    {

        $inputText = preg_replace('/[^\S\r\n]+/', ' ', $inputText);
        $inputText = preg_replace('/^(?=[^\s\r\n])\s+/m', '', $inputText);
        $paragraphs = preg_split('/\n\s*\n/', $inputText, 2, PREG_SPLIT_NO_EMPTY);

        // Check if there's at least one paragraph
        if (empty($paragraphs)) {
            return '';
        }

        // Get the first paragraph
        $firstParagraph = $this->trimSentences($paragraphs[0]);

        // Check the length of the first paragraph
        if (strlen($firstParagraph) <= $limit) {
            return $firstParagraph;
        } else {
            // Shorten the text to 250 characters
            return $this->cutToEndOfLastSentence(substr($firstParagraph, 0, $limit));
        }
    }

    public function formatDescription($description, $directions, $ingredients, $name)
    {
        $text = '';
        if (!empty($description)) {
            $text .= strip_tags(htmlspecialchars_decode($description));
        }
        if (!empty($directions)) {
            $text .= "\n<h3>DIRECTIONS</h3>\n" . strip_tags(htmlspecialchars_decode($directions));
        }
        if (!empty($ingredients)) {
            $text .= "\n<h3>INGREDIENTS</h3>\n" . strip_tags(htmlspecialchars_decode($ingredients));
        }
        if (empty($text) && $this->wooDefaultDesc()){
            $text .= $name;
        }
        return $text;
    }

    /**
     * Page through a search_read until all matching rows are fetched, so large
     * batched reads can never be silently truncated by the client's default
     * limit of 100.
     *
     * Subclasses must have set $this->client.
     */
    protected function searchReadAll(string $model, array $criteria, array $fields, int $pageSize = 500): array
    {
        $all    = [];
        $offset = 0;

        do {
            sleep($this->odooSleepSeconds());
            $page = $this->client->search_read($model, $criteria, $fields, $pageSize, $offset);
            $all = array_merge($all, $page);
            $offset += $pageSize;
        } while (count($page) === $pageSize);

        return $all;
    }
}
