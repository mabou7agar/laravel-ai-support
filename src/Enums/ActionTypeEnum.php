<?php

declare(strict_types=1);

namespace LaravelAIEngine\Enums;

/**
 * Action Type enumeration class (PHP 8.0 compatible)
 * Replaces native enum for Laravel 9 compatibility
 */
class ActionTypeEnum
{
    public const BUTTON = 'button';
    public const LINK = 'link';
    public const FORM = 'form';
    public const QUICK_REPLY = 'quick_reply';
    public const FILE_UPLOAD = 'file_upload';
    public const CONFIRM = 'confirm';
    public const MENU = 'menu';
    public const CARD = 'card';
    public const CAROUSEL = 'carousel';
    public const LIST = 'list';
    public const CALENDAR = 'calendar';
    public const LOCATION = 'location';
    public const PAYMENT = 'payment';
    public const SHARE = 'share';
    public const DOWNLOAD = 'download';
    public const COPY = 'copy';
    public const VOTE = 'vote';
    public const RATING = 'rating';
    public const SURVEY = 'survey';
    public const CUSTOM = 'custom';

    public string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Get action type description
     */
    public function description(): string
    {
        switch ($this->value) {
            case self::BUTTON:
                return 'Clickable button that triggers an action';
            case self::LINK:
                return 'Navigation link to internal or external URL';
            case self::FORM:
                return 'Interactive form with input fields';
            case self::QUICK_REPLY:
                return 'Pre-defined quick response option';
            case self::FILE_UPLOAD:
                return 'File upload interface';
            case self::CONFIRM:
                return 'Confirmation dialog with yes/no options';
            case self::MENU:
                return 'Dropdown menu with multiple options';
            case self::CARD:
                return 'Rich card with content and embedded actions';
            case self::CAROUSEL:
                return 'Horizontal scrollable card collection';
            case self::LIST:
                return 'Vertical list of selectable items';
            case self::CALENDAR:
                return 'Date/time picker interface';
            case self::LOCATION:
                return 'Location picker or map interface';
            case self::PAYMENT:
                return 'Payment processing interface';
            case self::SHARE:
                return 'Social sharing options';
            case self::DOWNLOAD:
                return 'File download action';
            case self::COPY:
                return 'Copy text to clipboard';
            case self::VOTE:
                return 'Voting interface (thumbs up/down, stars)';
            case self::RATING:
                return 'Rating scale (1-5 stars, 1-10 scale)';
            case self::SURVEY:
                return 'Multi-question survey form';
            case self::CUSTOM:
                return 'Custom action type with developer-defined behavior';
            default:
                return 'Unknown action type';
        }
    }

    /**
     * Get required data fields for this action type
     */
    public function requiredFields(): array
    {
        switch ($this->value) {
            case self::BUTTON:
                return ['action'];
            case self::LINK:
                return ['url'];
            case self::FORM:
                return ['fields'];
            case self::QUICK_REPLY:
                return ['message'];
            case self::FILE_UPLOAD:
                return ['allowed_types'];
            case self::CONFIRM:
                return ['confirm_action', 'cancel_action'];
            case self::MENU:
                return ['options'];
            case self::CARD:
                return ['title', 'content'];
            case self::CAROUSEL:
                return ['cards'];
            case self::LIST:
                return ['items'];
            case self::CALENDAR:
                return ['date_format'];
            case self::LOCATION:
                return ['map_provider'];
            case self::PAYMENT:
                return ['amount', 'currency'];
            case self::SHARE:
                return ['content'];
            case self::DOWNLOAD:
                return ['file_url'];
            case self::COPY:
                return ['text'];
            case self::VOTE:
                return ['vote_type'];
            case self::RATING:
                return ['scale', 'min_value', 'max_value'];
            case self::SURVEY:
                return ['questions'];
            case self::CUSTOM:
                return ['handler'];
            default:
                return [];
        }
    }

    /**
     * Get optional data fields for this action type
     */
    public function optionalFields(): array
    {
        switch ($this->value) {
            case self::BUTTON:
                return ['icon', 'variant', 'size'];
            case self::LINK:
                return ['target', 'external', 'icon'];
            case self::FORM:
                return ['method', 'validation', 'submit_text'];
            case self::QUICK_REPLY:
                return ['auto_send', 'icon'];
            case self::FILE_UPLOAD:
                return ['max_size', 'multiple', 'preview'];
            case self::CONFIRM:
                return ['title', 'icon', 'variant'];
            case self::MENU:
                return ['multiple', 'searchable', 'placeholder'];
            case self::CARD:
                return ['image_url', 'actions', 'footer'];
            case self::CAROUSEL:
                return ['auto_scroll', 'indicators'];
            case self::LIST:
                return ['multiple', 'searchable', 'icons'];
            case self::CALENDAR:
                return ['min_date', 'max_date', 'time_picker'];
            case self::LOCATION:
                return ['default_location', 'zoom_level'];
            case self::PAYMENT:
                return ['description', 'metadata'];
            case self::SHARE:
                return ['platforms', 'title', 'description'];
            case self::DOWNLOAD:
                return ['filename', 'file_type'];
            case self::COPY:
                return ['success_message'];
            case self::VOTE:
                return ['allow_change', 'show_results'];
            case self::RATING:
                return ['labels', 'show_value'];
            case self::SURVEY:
                return ['title', 'description', 'submit_text'];
            case self::CUSTOM:
                return ['config', 'metadata'];
            default:
                return [];
        }
    }

    /**
     * Check if action type supports multiple instances
     */
    public function supportsMultiple(): bool
    {
        switch ($this->value) {
            case self::BUTTON:
            case self::LINK:
            case self::QUICK_REPLY:
            case self::CARD:
                return true;
            default:
                return false;
        }
    }

    /**
     * Check if action type requires user interaction
     */
    public function requiresInteraction(): bool
    {
        switch ($this->value) {
            case self::LINK:
            case self::DOWNLOAD:
            case self::COPY:
            case self::SHARE:
                return false;
            default:
                return true;
        }
    }

    /**
     * Get default style configuration for action type
     */
    public function defaultStyle(): array
    {
        switch ($this->value) {
            case self::BUTTON:
                return [
                    'variant' => 'primary',
                    'size' => 'medium',
                    'full_width' => false
                ];
            case self::LINK:
                return [
                    'variant' => 'link',
                    'underline' => true,
                    'color' => 'blue'
                ];
            case self::FORM:
                return [
                    'layout' => 'vertical',
                    'spacing' => 'medium',
                    'border' => true
                ];
            case self::QUICK_REPLY:
                return [
                    'variant' => 'outline',
                    'size' => 'small',
                    'rounded' => true
                ];
            case self::FILE_UPLOAD:
                return [
                    'variant' => 'dashed',
                    'size' => 'large',
                    'drag_drop' => true
                ];
            case self::CONFIRM:
                return [
                    'variant' => 'modal',
                    'size' => 'medium',
                    'backdrop' => true
                ];
            case self::MENU:
                return [
                    'variant' => 'dropdown',
                    'size' => 'medium',
                    'searchable' => false
                ];
            case self::CARD:
                return [
                    'variant' => 'elevated',
                    'border_radius' => 'medium',
                    'shadow' => 'small'
                ];
            default:
                return [];
        }
    }

    /**
     * Get all available action types
     */
    public static function all(): array
    {
        return [
            self::BUTTON,
            self::LINK,
            self::FORM,
            self::QUICK_REPLY,
            self::FILE_UPLOAD,
            self::CONFIRM,
            self::MENU,
            self::CARD,
            self::CAROUSEL,
            self::LIST,
            self::CALENDAR,
            self::LOCATION,
            self::PAYMENT,
            self::SHARE,
            self::DOWNLOAD,
            self::COPY,
            self::VOTE,
            self::RATING,
            self::SURVEY,
            self::CUSTOM,
        ];
    }

    /**
     * Get all available action type instances
     */
    public static function cases(): array
    {
        return array_map(fn($value) => new self($value), self::all());
    }

    /**
     * Create action type from value
     */
    public static function from(string $value): self
    {
        if (!in_array($value, self::all())) {
            throw new \InvalidArgumentException("Invalid action type value: {$value}");
        }
        return new self($value);
    }
}
