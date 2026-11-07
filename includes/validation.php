<?php
declare(strict_types=1);
defined('APP_ROOT') || exit;

/**
 * Small server-side validator. Usage:
 *   $v = new Validator($_POST);
 *   $v->required('email', 'Email')->email('email', 'Email');
 *   if ($v->fails()) { $errors = $v->errors(); }
 */
final class Validator
{
    private array $errors = [];

    public function __construct(private array $data)
    {
    }

    public function value(string $field): string
    {
        $v = $this->data[$field] ?? '';
        return is_string($v) ? trim($v) : '';
    }

    private function has(string $field): bool
    {
        return $this->value($field) !== '';
    }

    public function add(string $field, string $message): self
    {
        $this->errors[$field] ??= $message;
        return $this;
    }

    public function required(string $field, string $label): self
    {
        if (!$this->has($field)) {
            $this->add($field, $label . ' is required.');
        }
        return $this;
    }

    public function max(string $field, int $max, string $label): self
    {
        if (mb_strlen($this->value($field)) > $max) {
            $this->add($field, $label . ' must be at most ' . $max . ' characters.');
        }
        return $this;
    }

    public function min(string $field, int $min, string $label): self
    {
        if ($this->has($field) && mb_strlen($this->value($field)) < $min) {
            $this->add($field, $label . ' must be at least ' . $min . ' characters.');
        }
        return $this;
    }

    public function email(string $field, string $label): self
    {
        if ($this->has($field) && (!filter_var($this->value($field), FILTER_VALIDATE_EMAIL) || mb_strlen($this->value($field)) > 190)) {
            $this->add($field, $label . ' must be a valid email address.');
        }
        return $this;
    }

    public function date(string $field, string $label, ?string $min = null, ?string $max = null): self
    {
        if (!$this->has($field)) {
            return $this;
        }
        $v = $this->value($field);
        $d = DateTime::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            return $this->add($field, $label . ' must be a valid date (YYYY-MM-DD).');
        }
        if ($min !== null && $v < $min) {
            $this->add($field, $label . ' must be on or after ' . fmt_date($min) . '.');
        }
        if ($max !== null && $v > $max) {
            $this->add($field, $label . ' must be on or before ' . fmt_date($max) . '.');
        }
        return $this;
    }

    public function datetime(string $field, string $label): self
    {
        if (!$this->has($field)) {
            return $this;
        }
        $v = str_replace('T', ' ', $this->value($field));
        $d = DateTime::createFromFormat('!Y-m-d H:i', substr($v, 0, 16));
        if (!$d || $d->format('Y-m-d H:i') !== substr($v, 0, 16)) {
            $this->add($field, $label . ' must be a valid date and time.');
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $label): self
    {
        if ($this->has($field) && !in_array($this->value($field), $allowed, true)) {
            $this->add($field, $label . ' has an invalid value.');
        }
        return $this;
    }

    public function int(string $field, string $label, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): self
    {
        if (!$this->has($field)) {
            return $this;
        }
        $v = $this->value($field);
        if (!preg_match('/^-?\d+$/', $v) || (int) $v < $min || (int) $v > $max) {
            $this->add($field, $label . ' must be a whole number between ' . $min . ' and ' . $max . '.');
        }
        return $this;
    }

    public function money(string $field, string $label): self
    {
        if ($this->has($field) && (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $this->value($field)))) {
            $this->add($field, $label . ' must be an amount like 150 or 150.00.');
        }
        return $this;
    }

    public function regex(string $field, string $pattern, string $message): self
    {
        if ($this->has($field) && !preg_match($pattern, $this->value($field))) {
            $this->add($field, $message);
        }
        return $this;
    }

    public function phone(string $field, string $label): self
    {
        return $this->regex($field, '/^\+?[0-9 ()\-]{6,25}$/', $label . ' must be a valid phone number (digits, spaces, + - ( ) only).');
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    public function errors(): array
    {
        return $this->errors;
    }
}

/** Convert '' to null for optional DB columns. */
function nullable(string $v): ?string
{
    return $v === '' ? null : $v;
}

function countries(): array
{
    static $list = null;
    if ($list === null) {
        $list = ['Afghanistan','Albania','Algeria','Andorra','Angola','Antigua and Barbuda','Argentina','Armenia','Australia','Austria','Azerbaijan','Bahamas','Bahrain','Bangladesh','Barbados','Belarus','Belgium','Belize','Benin','Bhutan','Bolivia','Bosnia and Herzegovina','Botswana','Brazil','Brunei','Bulgaria','Burkina Faso','Burundi','Cabo Verde','Cambodia','Cameroon','Canada','Central African Republic','Chad','Chile','China','Colombia','Comoros','Congo','Congo (Democratic Republic)','Costa Rica',"Côte d'Ivoire",'Croatia','Cuba','Cyprus','Czechia','Denmark','Djibouti','Dominica','Dominican Republic','Ecuador','Egypt','El Salvador','Equatorial Guinea','Eritrea','Estonia','Eswatini','Ethiopia','Fiji','Finland','France','Gabon','Gambia','Georgia','Germany','Ghana','Greece','Grenada','Guatemala','Guinea','Guinea-Bissau','Guyana','Haiti','Honduras','Hong Kong','Hungary','Iceland','India','Indonesia','Iran','Iraq','Ireland','Israel','Italy','Jamaica','Japan','Jordan','Kazakhstan','Kenya','Kiribati','Kosovo','Kuwait','Kyrgyzstan','Laos','Latvia','Lebanon','Lesotho','Liberia','Libya','Liechtenstein','Lithuania','Luxembourg','Macao','Madagascar','Malawi','Malaysia','Maldives','Mali','Malta','Marshall Islands','Mauritania','Mauritius','Mexico','Micronesia','Moldova','Monaco','Mongolia','Montenegro','Morocco','Mozambique','Myanmar','Namibia','Nauru','Nepal','Netherlands','New Zealand','Nicaragua','Niger','Nigeria','North Korea','North Macedonia','Norway','Oman','Pakistan','Palau','Palestine','Panama','Papua New Guinea','Paraguay','Peru','Philippines','Poland','Portugal','Qatar','Romania','Russia','Rwanda','Saint Kitts and Nevis','Saint Lucia','Saint Vincent and the Grenadines','Samoa','San Marino','Sao Tome and Principe','Saudi Arabia','Senegal','Serbia','Seychelles','Sierra Leone','Singapore','Slovakia','Slovenia','Solomon Islands','Somalia','South Africa','South Korea','South Sudan','Spain','Sri Lanka','Sudan','Suriname','Sweden','Switzerland','Syria','Taiwan','Tajikistan','Tanzania','Thailand','Timor-Leste','Togo','Tonga','Trinidad and Tobago','Tunisia','Turkey','Turkmenistan','Tuvalu','Uganda','Ukraine','United Arab Emirates','United Kingdom','United States','Uruguay','Uzbekistan','Vanuatu','Vatican City','Venezuela','Vietnam','Yemen','Zambia','Zimbabwe'];
    }
    return $list;
}
