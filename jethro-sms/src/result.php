<?php

/**
 * \Result — the Result monad used throughout the jethro-sms engine.
 *
 * Global (un-namespaced) class. Canonical home is this package; the Jethro
 * application requires this file from include/general.php, so there is
 * exactly one definition whether the package runs standalone or embedded.
 * See docs/extraction.md §1.
 */

if (!class_exists('Result', false)) {
/**
 * Result monad — a value that is either Success(T) or Failure(E).
 *
 * Failures carry an informational error payload (typically a string)
 * describing what went wrong, so callers can present a meaningful
 * message to the user without a separate error channel.
 *
 * Transform the value inside a Result with {@see map()}, which applies
 * a function to the success value while automatically propagating
 * failures.  Branch on success or failure with {@see isSuccess()} /
 * {@see isFailure()}, then extract the inner value with
 * {@see getValue()} or the error with {@see getError()}.
 *
 * @template T  The type of the success value.
 * @template E  The type of the error payload (usually string).
 *
 */
final class Result
{
	private function __construct(
		private readonly mixed $value,
		private readonly mixed $error,
		private readonly bool $isSuccess,
	) {
	}

	/**
	 * @param T $value
	 *
	 * @return self<T, E>
	 */
	public static function success(mixed $value): self
	{
		return new self($value, null, true);
	}

	/**
	 * @param E $error
	 *
	 * @return self<T, E>
	 */
	public static function failure(mixed $error): self
	{
		return new self(null, $error, false);
	}

	public function isSuccess(): bool
	{
		return $this->isSuccess;
	}

	public function isFailure(): bool
	{
		return !$this->isSuccess;
	}

	/**
	 * @return T
	 */
	public function getValue(): mixed
	{
		return $this->value;
	}

	/**
	 * @return E
	 */
	public function getError(): mixed
	{
		return $this->error;
	}

	/**
	 * If success, apply $fn to the value and return a new Result.
	 * If failure, propagate the error unchanged.
	 *
	 * @template U
	 * @param callable(T): U $fn
	 * @return Result<U, E>
	 */
	public function map(callable $fn): self
	{
		if ($this->isFailure()) {
			return $this;
		}
		return self::success($fn($this->value));
	}
}

}
