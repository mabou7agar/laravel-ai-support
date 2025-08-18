<?php

namespace LaravelAIEngine\Enums;

enum ActionTypeEnum: string
{
    case BUTTON = 'button';
    case LINK = 'link';
    case FORM = 'form';
    case QUICK_REPLY = 'quick_reply';
    case FILE_UPLOAD = 'file_upload';
    case CONFIRM = 'confirm';
    case MENU = 'menu';
    case CARD = 'card';
    case CAROUSEL = 'carousel';
    case LIST = 'list';
    case CALENDAR = 'calendar';
    case LOCATION = 'location';
    case PAYMENT = 'payment';
    case SHARE = 'share';
    case DOWNLOAD = 'download';
    case COPY = 'copy';
    case VOTE = 'vote';
    case RATING = 'rating';
    case SURVEY = 'survey';
    case CUSTOM = 'custom';

    /**
     * Get action type description
     */
    public function description(): string
    {
        return match($this) {
            self::BUTTON => 'Clickable button that triggers an action',
            self::LINK => 'Navigation link to internal or external URL',
            self::FORM => 'Interactive form with input fields',
            self::QUICK_REPLY => 'Pre-defined quick response option',
            self::FILE_UPLOAD => 'File upload interface',
            self::CONFIRM => 'Confirmation dialog with yes/no options',
            self::MENU => 'Dropdown menu with multiple options',
            self::CARD => 'Rich card with content and embedded actions',
            self::CAROUSEL => 'Horizontal scrollable card collection',
            self::LIST => 'Vertical list of selectable items',
            self::CALENDAR => 'Date/time picker interface',
            self::LOCATION => 'Location picker or map interface',
            self::PAYMENT => 'Payment processing interface',
            self::SHARE => 'Social sharing options',
            self::DOWNLOAD => 'File download action',
            self::COPY => 'Copy text to clipboard',
            self::VOTE => 'Voting interface (thumbs up/down, stars)',
            self::RATING => 'Rating scale (1-5 stars, 1-10 scale)',
            self::SURVEY => 'Multi-question survey form',
            self::CUSTOM => 'Custom action type with developer-defined behavior'
        };
    }

    /**
     * Get required data fields for this action type
     */
    public function requiredFields(): array
    {
        return match($this) {
            self::BUTTON => ['action'],
            self::LINK => ['url'],
            self::FORM => ['fields'],
            self::QUICK_REPLY => ['message'],
            self::FILE_UPLOAD => ['allowed_types'],
            self::CONFIRM => ['confirm_action', 'cancel_action'],
            self::MENU => ['options'],
            self::CARD => ['title', 'content'],
            self::CAROUSEL => ['cards'],
            self::LIST => ['items'],
            self::CALENDAR => ['date_format'],
            self::LOCATION => ['map_provider'],
            self::PAYMENT => ['amount', 'currency'],
            self::SHARE => ['content'],
            self::DOWNLOAD => ['file_url'],
            self::COPY => ['text'],
            self::VOTE => ['vote_type'],
            self::RATING => ['scale', 'min_value', 'max_value'],
            self::SURVEY => ['questions'],
            self::CUSTOM => ['handler']
        };
    }

    /**
     * Get optional data fields for this action type
     */
    public function optionalFields(): array
    {
        return match($this) {
            self::BUTTON => ['icon', 'variant', 'size'],
            self::LINK => ['target', 'external', 'icon'],
            self::FORM => ['method', 'validation', 'submit_text'],
            self::QUICK_REPLY => ['auto_send', 'icon'],
            self::FILE_UPLOAD => ['max_size', 'multiple', 'preview'],
            self::CONFIRM => ['title', 'icon', 'variant'],
            self::MENU => ['multiple', 'searchable', 'placeholder'],
            self::CARD => ['image_url', 'actions', 'footer'],
            self::CAROUSEL => ['auto_scroll', 'indicators'],
            self::LIST => ['multiple', 'searchable', 'icons'],
            self::CALENDAR => ['min_date', 'max_date', 'time_picker'],
            self::LOCATION => ['default_location', 'zoom_level'],
            self::PAYMENT => ['description', 'metadata'],
            self::SHARE => ['platforms', 'title', 'description'],
            self::DOWNLOAD => ['filename', 'file_type'],
            self::COPY => ['success_message'],
            self::VOTE => ['allow_change', 'show_results'],
            self::RATING => ['labels', 'show_value'],
            self::SURVEY => ['title', 'description', 'submit_text'],
            self::CUSTOM => ['config', 'metadata']
        };
    }

    /**
     * Check if action type supports multiple instances
     */
    public function supportsMultiple(): bool
    {
        return match($this) {
            self::BUTTON, self::LINK, self::QUICK_REPLY, self::CARD => true,
            self::FORM, self::FILE_UPLOAD, self::CONFIRM, self::MENU,
            self::CAROUSEL, self::LIST, self::CALENDAR, self::LOCATION,
            self::PAYMENT, self::SHARE, self::DOWNLOAD, self::COPY,
            self::VOTE, self::RATING, self::SURVEY, self::CUSTOM => false
        };
    }

    /**
     * Check if action type requires user interaction
     */
    public function requiresInteraction(): bool
    {
        return match($this) {
            self::LINK, self::DOWNLOAD, self::COPY, self::SHARE => false,
            default => true
        };
    }

    /**
     * Get default style configuration for action type
     */
    public function defaultStyle(): array
    {
        return match($this) {
            self::BUTTON => [
                'variant' => 'primary',
                'size' => 'medium',
                'full_width' => false
            ],
            self::LINK => [
                'variant' => 'link',
                'underline' => true,
                'color' => 'blue'
            ],
            self::FORM => [
                'layout' => 'vertical',
                'spacing' => 'medium',
                'border' => true
            ],
            self::QUICK_REPLY => [
                'variant' => 'outline',
                'size' => 'small',
                'rounded' => true
            ],
            self::FILE_UPLOAD => [
                'variant' => 'dashed',
                'size' => 'large',
                'drag_drop' => true
            ],
            self::CONFIRM => [
                'variant' => 'modal',
                'size' => 'medium',
                'backdrop' => true
            ],
            self::MENU => [
                'variant' => 'dropdown',
                'size' => 'medium',
                'searchable' => false
            ],
            self::CARD => [
                'variant' => 'elevated',
                'border_radius' => 'medium',
                'shadow' => 'small'
            ],
            default => []
        };
    }
}
