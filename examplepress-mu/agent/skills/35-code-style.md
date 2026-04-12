# Code style — PHP formatting fundamentals

These rules govern the PHP code you generate inside templates,
bootstrap files, `rpc.php`, `cron.php`, and `db.php`. The worked
examples throughout the curriculum already follow these conventions —
this skill makes them explicit so you apply them consistently.

## Strings

- **Single quotes** for all strings unless you are interpolating a
  variable (`"Hello {$name}"`).
- **`sprintf` / `vsprintf`** for complex interpolation instead of
  concatenation chains. Easier to read, easier to translate:

  ```php
  // WRONG
  $msg = 'Hello ' . esc_html( $name ) . ', you have ' . (int) $count . ' items.';

  // RIGHT
  $msg = sprintf(
      esc_html__( 'Hello %s, you have %d items.', '{slug}' ),
      esc_html( $name ),
      (int) $count
  );
  ```

## Arrays

- **Short array syntax** — `[]`, never `array()`.
- **Trailing commas** on the last item of every multiline array and
  every multiline function call:

  ```php
  $args = [
      'post_type'      => 'post',
      'posts_per_page' => 5,
      'orderby'        => 'date',  // ← trailing comma
  ];
  ```

## Control structures

- **Always use braces** for `if`, `else`, `elseif`, `for`, `foreach`,
  `while`, `do`, `switch`.
- **Exception — HTML-interleaved templates:** In PHP template markup
  that alternates between PHP and HTML, the WordPress alternative
  syntax (`if/endif`, `foreach/endforeach`, `while/endwhile`) is the
  standard pattern and is permitted:

  ```php
  <?php if ( ! empty( $attributes['posts'] ) ) : ?>
      <ul>
          <?php foreach ( $attributes['posts'] as $post ) : ?>
              <li><?php echo esc_html( get_the_title( $post ) ); ?></li>
          <?php endforeach; ?>
      </ul>
  <?php endif; ?>
  ```

  In pure PHP blocks (bootstrap, `rpc.php`, `cron.php`, `db.php`
  callbacks), always use braces.

## Output buffering — banned

**Never use `ob_start()` / `ob_get_clean()` / `ob_end_clean()`.** In
any file. Blockstudio templates render inline — output buffering
interferes with block rendering, breaks nested block output, and
makes debugging impossible. If you think you need output buffering,
you are solving the wrong problem.

## Procedural over OOP

- **Do not define classes** in generated code. Use closures and
  functions. The bootstrap pattern (skill 10) is procedural by design:
  `add_action` with closures, `examplepress_register_route_origin`
  with a plain array.
- **No unnecessary intermediate variables.** If a value is used once,
  inline it. If a ternary or null coalesce replaces a 4-line
  conditional, use the shorter form.
- **No unnecessary abstractions.** Three similar lines of code are
  better than a premature helper function. Only extract when the same
  logic appears three or more times in different files.

## Formatting basics

- **Spaces inside parentheses** for control structures:
  `if ( $condition )`, `foreach ( $items as $item )`.
- **No spaces inside parentheses** for function calls:
  `esc_html( $value )` — WordPress coding standards use spaces here
  too, and the curriculum examples follow this convention.
- **One blank line** between logical sections (hook registrations,
  template blocks, function definitions).
- **No trailing whitespace.**

## Hard rules

- Single quotes unless interpolating.
- Short array syntax only.
- Trailing commas on multiline constructs.
- No `ob_start` / `ob_get_clean` / output buffering of any kind.
- No class definitions in generated code.
- Braces for control structures in pure PHP; alternative syntax
  (`if/endif`) only in HTML-interleaved templates.
