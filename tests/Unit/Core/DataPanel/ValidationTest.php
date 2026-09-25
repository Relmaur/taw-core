<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\DataPanel;

use TAW\Core\DataPanel\Validation;
use TAW\Core\Metabox\Metabox;
use TAW\Tests\TestCase;

final class ValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Metabox::forgetInstances();
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function box(array $fields): Metabox
    {
        return new Metabox(['id' => 'book_details', 'title' => 'Book', 'screens' => ['book'], 'fields' => $fields]);
    }

    /**
     * @param array<string, mixed> $stored meta key or taw_ field => value
     */
    private function stored(array $stored = []): callable
    {
        return static fn (string $kind, string $key): mixed => $stored[$key] ?? '';
    }

    public function test_a_valid_save_passes(): void
    {
        $box = $this->box([['id' => 'book_author', 'type' => 'text', 'required' => true]]);

        $this->assertSame(['errors' => [], 'clear' => []], Validation::check($box, ['_taw_book_author' => 'Ada'], [], $this->stored()));
    }

    public function test_required_uses_the_stored_value_when_the_request_omits_it(): void
    {
        $box = $this->box([['id' => 'book_author', 'type' => 'text', 'label' => 'Author', 'required' => true]]);

        $this->assertSame([], Validation::check($box, [], [], $this->stored(['_taw_book_author' => 'Ada']))['errors']);
        $this->assertSame(
            [['field' => '_taw_book_author', 'message' => 'Author is required.']],
            Validation::check($box, [], [], $this->stored())['errors']
        );
        $this->assertCount(1, Validation::check($box, ['_taw_book_author' => ''], [], $this->stored(['_taw_book_author' => 'Ada']))['errors']);
    }

    public function test_required_structured_fields_must_have_a_value(): void
    {
        $box = $this->box([['id' => 'book_files', 'type' => 'files', 'label' => 'Files', 'required' => true]]);

        $this->assertSame([['field' => 'taw_book_files', 'message' => 'Files is required.']], Validation::check($box, [], ['taw_book_files' => []], $this->stored())['errors']);
        $this->assertSame([], Validation::check($box, [], ['taw_book_files' => [12]], $this->stored())['errors']);
    }

    public function test_validate_callbacks_run_on_sent_values_only(): void
    {
        $calls = [];
        $box   = $this->box([[
            'id'       => 'book_isbn',
            'type'     => 'text',
            'label'    => 'ISBN',
            'validate' => static function (mixed $value) use (&$calls): bool|string {
                $calls[] = $value;

                return strlen((string) $value) === 13 ? true : 'ISBN must have 13 digits.';
            },
        ]]);

        $this->assertSame([['field' => '_taw_book_isbn', 'message' => 'ISBN must have 13 digits.']], Validation::check($box, ['_taw_book_isbn' => '123'], [], $this->stored())['errors']);
        $this->assertSame([], Validation::check($box, [], [], $this->stored(['_taw_book_isbn' => 'bad']))['errors'], 'untouched values are not re-validated');
        $this->assertSame(['123'], $calls);
    }

    public function test_a_validate_callback_returning_false_gets_a_generic_message(): void
    {
        $box = $this->box([['id' => 'n', 'type' => 'number', 'label' => 'Count', 'validate' => static fn (): bool => false]]);

        $this->assertSame([['field' => '_taw_n', 'message' => 'Count is invalid.']], Validation::check($box, ['_taw_n' => 3], [], $this->stored())['errors']);
    }

    public function test_readonly_fields_cannot_be_written(): void
    {
        $box = $this->box([['id' => 'book_code', 'type' => 'text', 'label' => 'Code', 'readonly' => true, 'required' => true]]);

        $this->assertSame([['field' => '_taw_book_code', 'message' => 'Code is read-only.']], Validation::check($box, ['_taw_book_code' => 'X'], [], $this->stored())['errors']);
        $this->assertSame([], Validation::check($box, [], [], $this->stored())['errors'], 'not sent: nothing to refuse, and required is not enforced');
    }

    public function test_hidden_fields_are_skipped_and_cleared(): void
    {
        $box = $this->box([
            ['id' => 'has_sale', 'type' => 'checkbox'],
            ['id' => 'sale_price', 'type' => 'number', 'required' => true, 'conditions' => [['id' => 'has_sale', 'value' => '1']]],
            ['id' => 'sale', 'type' => 'group', 'conditions' => [['id' => 'has_sale', 'operator' => '!empty']], 'fields' => [['id' => 'ends', 'type' => 'datepicker']]],
        ]);

        $hidden = Validation::check($box, ['_taw_has_sale' => false], [], $this->stored(['_taw_sale_price' => 10]));
        $this->assertSame([], $hidden['errors'], 'required is not enforced on a hidden field');
        $this->assertSame(['_taw_sale_price', '_taw_sale_ends'], $hidden['clear']);

        $shown = Validation::check($box, ['_taw_has_sale' => true], [], $this->stored());
        $this->assertSame([['field' => '_taw_sale_price', 'message' => 'sale_price is required.']], $shown['errors']);
        $this->assertSame([], $shown['clear']);
    }

    public function test_group_sub_fields_are_checked_under_their_compound_keys(): void
    {
        $box = $this->box([['id' => 'book_meta', 'type' => 'group', 'fields' => [
            ['id' => 'isbn', 'type' => 'text', 'label' => 'ISBN', 'required' => true],
            ['id' => 'code', 'type' => 'text', 'label' => 'Code', 'readonly' => true],
        ]]]);

        $this->assertSame([
            ['field' => '_taw_book_meta_isbn', 'message' => 'ISBN is required.'],
            ['field' => '_taw_book_meta_code', 'message' => 'Code is read-only.'],
        ], Validation::check($box, ['_taw_book_meta_code' => 'X'], [], $this->stored())['errors']);
    }

    public function test_condition_operators_match_the_metabox(): void
    {
        $values = ['kind' => 'ebook-pdf', 'flag' => true, 'none' => ''];

        $this->assertTrue(Validation::conditionsMet([['id' => 'kind', 'operator' => 'contains', 'value' => 'pdf']], $values));
        $this->assertTrue(Validation::conditionsMet([['id' => 'kind', 'operator' => '!=', 'value' => 'print']], $values));
        $this->assertTrue(Validation::conditionsMet([['id' => 'flag', 'value' => '1']], $values), 'true posts as "1"');
        $this->assertTrue(Validation::conditionsMet([['id' => 'none', 'operator' => 'empty']], $values));
        $this->assertTrue(Validation::conditionsMet([['field' => 'kind', 'operator' => '!empty']], $values), '"field" is accepted like "id"');
        $this->assertFalse(Validation::conditionsMet([['id' => 'kind', 'operator' => '!empty'], ['id' => 'none', 'operator' => '!empty']], $values), 'AND');
    }
}
