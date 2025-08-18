<?php

namespace LaravelAIEngine\Tests\Unit\DTOs;

use LaravelAIEngine\DTOs\InteractiveAction;
use LaravelAIEngine\Enums\ActionTypeEnum;
use LaravelAIEngine\Tests\TestCase;

class InteractiveActionTest extends TestCase
{
    public function test_create_button_action()
    {
        $action = InteractiveAction::button('btn1', 'Click Me', ['test' => 'data']);

        $this->assertEquals('btn1', $action->id);
        $this->assertEquals(ActionTypeEnum::BUTTON, $action->type);
        $this->assertEquals('Click Me', $action->label);
        $this->assertEquals(['test' => 'data'], $action->data);
    }

    public function test_create_link_action()
    {
        $action = InteractiveAction::link('link1', 'Visit Site', 'https://example.com', 'External link', true);

        $this->assertEquals('link1', $action->id);
        $this->assertEquals(ActionTypeEnum::LINK, $action->type);
        $this->assertEquals('Visit Site', $action->label);
        $this->assertEquals('https://example.com', $action->data['url']);
        $this->assertTrue($action->data['external']);
        $this->assertEquals('_blank', $action->data['target']);
    }

    public function test_create_form_action()
    {
        $fields = [
            ['name' => 'email', 'type' => 'email', 'required' => true],
            ['name' => 'message', 'type' => 'textarea']
        ];

        $action = InteractiveAction::form('form1', 'Contact Form', $fields);

        $this->assertEquals('form1', $action->id);
        $this->assertEquals(ActionTypeEnum::FORM, $action->type);
        $this->assertEquals('Contact Form', $action->label);
        $this->assertEquals($fields, $action->data['fields']);
        $this->assertEquals('POST', $action->data['method']);
    }

    public function test_create_quick_reply_action()
    {
        $action = InteractiveAction::quickReply('reply1', 'Yes', 'I agree with this');

        $this->assertEquals('reply1', $action->id);
        $this->assertEquals(ActionTypeEnum::QUICK_REPLY, $action->type);
        $this->assertEquals('Yes', $action->label);
        $this->assertEquals('I agree with this', $action->data['message']);
        $this->assertTrue($action->data['auto_send']);
    }

    public function test_create_file_upload_action()
    {
        $action = InteractiveAction::fileUpload('upload1', 'Upload Image', ['image/*'], 5242880, false);

        $this->assertEquals('upload1', $action->id);
        $this->assertEquals(ActionTypeEnum::FILE_UPLOAD, $action->type);
        $this->assertEquals('Upload Image', $action->label);
        $this->assertEquals(['image/*'], $action->data['allowed_types']);
        $this->assertEquals(5242880, $action->data['max_size']);
        $this->assertFalse($action->data['multiple']);
    }

    public function test_create_confirm_action()
    {
        $action = InteractiveAction::confirm('confirm1', 'Delete', 'Are you sure?', ['id' => 123]);

        $this->assertEquals('confirm1', $action->id);
        $this->assertEquals(ActionTypeEnum::CONFIRM, $action->type);
        $this->assertEquals('Delete', $action->label);
        $this->assertEquals('Are you sure?', $action->confirmMessage);
        $this->assertEquals(['id' => 123], $action->data);
    }

    public function test_create_menu_action()
    {
        $options = [
            ['value' => 'option1', 'label' => 'Option 1'],
            ['value' => 'option2', 'label' => 'Option 2']
        ];

        $action = InteractiveAction::menu('menu1', 'Select Option', $options);

        $this->assertEquals('menu1', $action->id);
        $this->assertEquals(ActionTypeEnum::MENU, $action->type);
        $this->assertEquals('Select Option', $action->label);
        $this->assertEquals($options, $action->data['options']);
        $this->assertFalse($action->data['multiple']);
    }

    public function test_create_card_action()
    {
        $actions = [
            InteractiveAction::button('btn1', 'Action 1', []),
            InteractiveAction::button('btn2', 'Action 2', [])
        ];

        $action = InteractiveAction::card('card1', 'Product Card', 'Description', 'image.jpg', $actions);

        $this->assertEquals('card1', $action->id);
        $this->assertEquals(ActionTypeEnum::CARD, $action->type);
        $this->assertEquals('Product Card', $action->label);
        $this->assertEquals('Product Card', $action->data['title']);
        $this->assertEquals('Description', $action->data['content']);
        $this->assertEquals('image.jpg', $action->data['image_url']);
        $this->assertEquals($actions, $action->data['actions']);
    }

    public function test_disabled_action()
    {
        $action = InteractiveAction::button('btn1', 'Click Me', []);
        $disabledAction = $action->disabled(true);

        $this->assertFalse($action->disabled);
        $this->assertTrue($disabledAction->disabled);
        $this->assertNotSame($action, $disabledAction); // Immutable
    }

    public function test_loading_action()
    {
        $action = InteractiveAction::button('btn1', 'Click Me', []);
        $loadingAction = $action->loading(true);

        $this->assertFalse($action->loading);
        $this->assertTrue($loadingAction->loading);
        $this->assertNotSame($action, $loadingAction); // Immutable
    }

    public function test_with_confirmation()
    {
        $action = InteractiveAction::button('btn1', 'Click Me', []);
        $confirmedAction = $action->withConfirmation('Are you sure?');

        $this->assertNull($action->confirmMessage);
        $this->assertEquals('Are you sure?', $confirmedAction->confirmMessage);
        $this->assertNotSame($action, $confirmedAction); // Immutable
    }

    public function test_to_array()
    {
        $action = InteractiveAction::button('btn1', 'Click Me', ['test' => 'data'], 'Description');
        $array = $action->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('btn1', $array['id']);
        $this->assertEquals('button', $array['type']);
        $this->assertEquals('Click Me', $array['label']);
        $this->assertEquals('Description', $array['description']);
        $this->assertEquals(['test' => 'data'], $array['data']);
        $this->assertFalse($array['disabled']);
        $this->assertFalse($array['loading']);
    }

    public function test_from_array()
    {
        $data = [
            'id' => 'btn1',
            'type' => 'button',
            'label' => 'Click Me',
            'description' => 'Test button',
            'data' => ['test' => 'data'],
            'disabled' => true,
            'loading' => false,
            'confirm_message' => 'Are you sure?'
        ];

        $action = InteractiveAction::fromArray($data);

        $this->assertEquals('btn1', $action->id);
        $this->assertEquals(ActionTypeEnum::BUTTON, $action->type);
        $this->assertEquals('Click Me', $action->label);
        $this->assertEquals('Test button', $action->description);
        $this->assertEquals(['test' => 'data'], $action->data);
        $this->assertTrue($action->disabled);
        $this->assertFalse($action->loading);
        $this->assertEquals('Are you sure?', $action->confirmMessage);
    }

    public function test_round_trip_serialization()
    {
        $original = InteractiveAction::button('btn1', 'Click Me', ['test' => 'data'])
            ->disabled(true)
            ->withConfirmation('Are you sure?');

        $array = $original->toArray();
        $restored = InteractiveAction::fromArray($array);

        $this->assertEquals($original->id, $restored->id);
        $this->assertEquals($original->type, $restored->type);
        $this->assertEquals($original->label, $restored->label);
        $this->assertEquals($original->data, $restored->data);
        $this->assertEquals($original->disabled, $restored->disabled);
        $this->assertEquals($original->confirmMessage, $restored->confirmMessage);
    }
}
