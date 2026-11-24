<?php

namespace App\Domain\Analysis;

use App\Domain\Discovery\ValueResolver;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;

/**
 * Decides, from a middleware's source, whether it authenticates. Never by name, and never by
 * "a 401 appears somewhere": every path through handle() that reaches $next($request) is
 * followed, and the verdict depends on what each path checked.
 *
 * - authenticates: every path verified a credential (a guard user, a token looked up in the
 *   database, an `exists:` rule on a token field, hash_equals/Hash::check).
 * - presence_only: every path needs a credential to be present, but some path never verifies it.
 * - guest_or_user: some path passes on a credential, another on a plain request value (e.g. guest_id).
 * - conditional: some path reaches the controller with no credential check at all, others do check.
 * - none: no path checks credentials.
 * - unknown: the code can't be followed (missing class, loops, delegation to unread code).
 */
final class MiddlewareInspector
{
    /** Framework and package middleware with known behavior (read from their documented source). */
    private const KNOWN = [
        'illuminate\auth\middleware\authenticate' => 'authenticate',
        'illuminate\auth\middleware\authenticatewithbasicauth' => 'basic',
        'laravel\sanctum\http\middleware\checkabilities' => 'token_user',
        'laravel\sanctum\http\middleware\checkforanyability' => 'token_user',
        'laravel\passport\http\middleware\checkscopes' => 'token_user',
        'laravel\passport\http\middleware\checkforanyscope' => 'token_user',
        'laravel\passport\http\middleware\checktoken' => 'token_user',
        'laravel\passport\http\middleware\checktokenforanyscope' => 'token_user',
        'laravel\passport\http\middleware\checkclientcredentials' => 'client_token',
        'laravel\passport\http\middleware\checkclientcredentialsforanyscope' => 'client_token',
        'illuminate\auth\middleware\authorize' => 'authorize',
        'illuminate\auth\middleware\ensureemailisverified' => 'access',
        'illuminate\auth\middleware\requirepassword' => 'access',
        'illuminate\routing\middleware\validatesignature' => 'access',
        'illuminate\auth\middleware\redirectifauthenticated' => 'guest_only',
        'illuminate\routing\middleware\throttlerequests' => 'rate_limit',
        'illuminate\routing\middleware\throttlerequestswithredis' => 'rate_limit',
        'illuminate\session\middleware\authenticatesession' => 'none',
        'illuminate\routing\middleware\substitutebindings' => 'none',
        'illuminate\http\middleware\handlecors' => 'none',
        'illuminate\http\middleware\trustproxies' => 'none',
        'illuminate\http\middleware\trusthosts' => 'none',
        'illuminate\http\middleware\setcacheheaders' => 'none',
        'illuminate\http\middleware\validatepostsize' => 'none',
        'illuminate\http\middleware\addlinkheadersforpreloadedassets' => 'none',
        'illuminate\foundation\http\middleware\preventrequestsduringmaintenance' => 'none',
        'illuminate\foundation\http\middleware\checkformaintenancemode' => 'none',
        'illuminate\foundation\http\middleware\trimstrings' => 'none',
        'illuminate\foundation\http\middleware\convertemptystringstonull' => 'none',
        'illuminate\foundation\http\middleware\validatecsrftoken' => 'none',
        'illuminate\foundation\http\middleware\verifycsrftoken' => 'none',
        'illuminate\foundation\http\middleware\handleprecognitiverequests' => 'none',
        'illuminate\foundation\http\middleware\invokedeferredcallbacks' => 'none',
        'illuminate\cookie\middleware\encryptcookies' => 'none',
        'illuminate\cookie\middleware\addqueuedcookiestoresponse' => 'none',
        'illuminate\session\middleware\startsession' => 'none',
        'illuminate\view\middleware\shareerrorsfromsession' => 'none',
        'laravel\sanctum\http\middleware\ensurefrontendrequestsarestateful' => 'none',
        'inertia\middleware' => 'none',
        'fruitcake\cors\handlecors' => 'none',
    ];

    /** Request inputs whose names mark them as credentials. */
    private const CREDENTIAL_NAME = '/(^|[_-])(token|api[_-]?key|secret|password|auth|authorization)([_-]|$)/i';

    private const GUARD_CHECKS = ['user', 'check', 'guest', 'id', 'authenticate', 'validate', 'attempt', 'once', 'onceusingid', 'hasuser'];

    private const LOOKUP_ENDS = ['first', 'exists', 'doesntexist', 'count', 'find', 'firstorfail', 'findorfail', 'value', 'get', 'sole', 'pluck'];

    private ClassInfo $class;

    private string $req = 'request';

    private string $next = 'next';

    /** @var array<string, array{rank: int, validator: int, credentials: list<string>}> */
    private array $vars = [];

    /** @var list<list<array{rank: int, line: int, involves: bool, positive: bool}>> facts per path that reaches */
    private array $passes = [];

    /** @var list<string> */
    private array $credentials = [];

    private ?string $opaque = null;

    /** @var list<string> action variables holding the signed-in user or token */
    private array $identityVars = [];

    public function __construct(
        private readonly ClassIndex $index,
        private readonly Evidence $evidence,
        /** @var array<string, string> guard => driver, from config/auth.php ('?' when unreadable) */
        private readonly array $guards,
        private readonly ?string $defaultGuard,
    ) {}

    /**
     * @param  list<string>  $params
     * @return array{verdict: string, kind: string, status: string, credentials: list<string>, note: string|null, rejects: list<int>, evidence: list<string>}
     */
    public function inspect(string $class, array $params): array
    {
        $known = self::KNOWN[strtolower($class)] ?? null;
        if ($known !== null) {
            return $this->known($known, $params, []);
        }

        $info = $this->index->find($class);
        if ($info === null) {
            return self::result('unknown', 'unknown', 'unknown', [], str_starts_with($class, 'Illuminate\\')
                ? "{$class} is a framework class this analyzer doesn't recognize"
                : "{$class} was not found in this snapshot (or the class lookup limit was reached), so it couldn't be read", [], []);
        }

        $found = $this->index->method($info, 'handle');
        $ancestor = $this->knownAncestor($info);

        // A subclass of a known middleware (e.g. Laravel's Authenticate) that doesn't replace handle(), or calls back into it.
        if ($ancestor !== null && ($found === null || $this->delegatesToParent($found[1]))) {
            return $this->known($ancestor, $params, [$this->evidence->add($info->file->path, $info->sha256, $info->fqcn, $info->node, 'middleware')]);
        }

        if ($found === null) {
            return self::result('unknown', 'unknown', 'unknown', [], "{$class} has no handle() that could be read (it may come from a package)", [], []);
        }

        [$owner, $handle] = $found;

        return $this->paths($owner, $handle);
    }

    /**
     * A controller action can refuse guests itself: `if (! auth('api')->user()) return …, 403`.
     * Returns null when the action never looks at credentials.
     *
     * @return array{verdict: string, kind: string, status: string, credentials: list<string>, note: string|null, rejects: list<int>, evidence: list<string>}|null
     */
    public function action(ClassInfo $owner, Stmt\ClassMethod $method, ?string $requestVar): ?array
    {
        $this->class = $owner;
        $this->vars = [];
        $this->credentials = [];
        $this->req = $requestVar ?? 'request';
        $this->next = "\0"; // actions have no $next
        // A login action signs the caller in (attempt/login); user checks after that are about the
        // account it just verified, not about an earlier sign-in.
        if ((new NodeFinder)->findFirst($method->stmts ?? [], fn (Node $n) => $n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier
            && in_array(strtolower($n->name->toString()), ['attempt', 'login', 'loginusingid', 'once', 'onceusingid'], true) && $this->isGuard($n->var)) !== null) {
            return null;
        }

        $this->collectVars($method->stmts ?? []);
        $this->identityVars = [];
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Expr\Assign::class) as $assign) {
            if ($assign->var instanceof Expr\Variable && is_string($assign->var->name)
                && (new NodeFinder)->findFirst([$assign->expr], fn (Node $n) => $n instanceof Expr && $this->isIdentityRead($n)) !== null) {
                $this->identityVars[] = $assign->var->name;
            }
        }
        $symbol = $owner->symbol($method->name->toString());
        $ev = fn (Node $n) => [$this->evidence->add($owner->file->path, $owner->sha256, $symbol, $n, 'authorization')];

        // Guards before the action does anything else that could answer.
        foreach ($method->stmts ?? [] as $stmt) {
            $guard = null;
            if ($stmt instanceof Stmt\If_ && $stmt->elseifs === [] && $stmt->else === null && ($code = $this->refusal($stmt->stmts)) !== null) {
                $guard = [$this->holds($stmt->cond, false), $code, $stmt, $stmt->cond];
            } elseif ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\FuncCall && $stmt->expr->name instanceof Node\Name
                && in_array(strtolower($stmt->expr->name->toString()), ['abort_if', 'abort_unless'], true) && ($cond = $stmt->expr->args[0] ?? null) instanceof Node\Arg
                && in_array($code = $this->statusArg($stmt->expr->args[1] ?? null), [401, 403], true)) {
                $guard = [$this->holds($cond->value, strtolower($stmt->expr->name->toString()) === 'abort_unless'), $code, $stmt, $cond->value];
            }
            if ($guard !== null && $guard[0] === 3 && $this->usesIdentity($guard[3])) {
                return self::result('authenticates', 'authentication', 'verified', array_values(array_unique($this->credentials)),
                    "the action refuses with {$guard[1]} unless a verified user is present (line {$guard[2]->getStartLine()})", [$guard[1]], $ev($guard[2]));
            }
            if ($stmt instanceof Stmt\Return_ || ($stmt instanceof Stmt\If_ && $this->refusal($stmt->stmts) === null && (new NodeFinder)->findFirstInstanceOf([$stmt], Stmt\Return_::class) !== null)) {
                break;
            }
        }

        // A refusal deeper in the action that depends on the user: not provable either way.
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Stmt\If_::class) as $if) {
            $code = $this->refusal($if->stmts);
            if ($code !== null && $this->usesIdentity($if->cond)) {
                $credentials = array_values(array_unique($this->credentials));

                return self::result('unknown', 'unknown', 'unknown', $credentials === [] ? ['an authenticated user'] : $credentials,
                    "the action can refuse with {$code} depending on the signed-in user (line {$if->getStartLine()})", [$code], $ev($if));
            }
        }

        $reads = (new NodeFinder)->findFirst($method->stmts ?? [], fn (Node $n) => $n instanceof Expr && $this->isIdentityRead($n));

        return $reads === null ? null : self::result('optional', 'optional_auth', 'verified', [],
            "the action reads the signed-in user when there is one (line {$reads->getStartLine()}), but never refuses without one", [], $ev($reads));
    }

    /**
     * 401/403 when these statements end the request with that status, else null.
     *
     * @param  array<Node>  $stmts
     */
    private function refusal(array $stmts): ?int
    {
        $ends = (new NodeFinder)->findFirst($stmts, fn (Node $n) => $n instanceof Stmt\Return_ || $n instanceof Expr\Throw_
            || ($n instanceof Expr\FuncCall && $n->name instanceof Node\Name && strtolower($n->name->toString()) === 'abort'));
        if ($ends === null) {
            return null;
        }
        foreach ((new NodeFinder)->find($stmts, fn (Node $n) => $n instanceof Scalar\Int_ || $n instanceof Expr\ClassConstFetch || $n instanceof Expr\New_) as $n) {
            if ($n instanceof Scalar\Int_ && in_array($n->value, [401, 403], true)) {
                return $n->value;
            }
            if ($n instanceof Expr\ClassConstFetch && $n->name instanceof Node\Identifier && in_array($n->name->toString(), ['HTTP_UNAUTHORIZED', 'HTTP_FORBIDDEN'], true)) {
                return $n->name->toString() === 'HTTP_UNAUTHORIZED' ? 401 : 403;
            }
            if ($n instanceof Expr\New_ && $n->class instanceof Node\Name && in_array(strtolower($n->class->getLast()), ['authenticationexception', 'unauthorizedhttpexception'], true)) {
                return 401;
            }
        }

        return null;
    }

    /**
     * Does this code look at who is signed in (guard user, $request->user(), the bearer token)?
     * A login form checking a password does not count: it doesn't need an existing sign-in.
     */
    private function usesIdentity(Node $node): bool
    {
        if ((new NodeFinder)->findFirst([$node], fn (Node $n) => $n instanceof Expr && $this->isIdentityRead($n)) !== null) {
            return true;
        }
        // through a variable: $user = auth('api')->user(); if (! $user) …
        foreach ((new NodeFinder)->findInstanceOf([$node], Expr\Variable::class) as $var) {
            if (is_string($var->name) && in_array($var->name, $this->identityVars, true)) {
                return true;
            }
        }

        return false;
    }

    private function isIdentityRead(Expr $n): bool
    {
        $who = ['user', 'check', 'guest', 'id', 'authenticate', 'hasuser'];

        return ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && $this->isGuard($n->var)
                && in_array(strtolower($n->name->toString()), $who, true))
            || ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name && $n->name instanceof Node\Identifier && $this->isFacade($n->class, 'auth')
                && in_array(strtolower($n->name->toString()), $who, true))
            || ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && $this->isRequest($n->var)
                && (in_array(strtolower($n->name->toString()), ['user', 'bearertoken'], true)
                    || (strtolower($n->name->toString()) === 'header' && $this->class->file->resolver()->arg($n->args, 0) === 'Authorization')));
    }

    private function statusArg(?Node $arg): ?int
    {
        $value = $arg instanceof Node\Arg ? $this->class->file->resolver()->resolve($arg->value) : null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param  list<string>  $params
     * @param  list<string>  $evidence
     * @return array{verdict: string, kind: string, status: string, credentials: list<string>, note: string|null, rejects: list<int>, evidence: list<string>}
     */
    private function known(string $known, array $params, array $evidence): array
    {
        return match ($known) {
            'authenticate' => self::result('authenticates', 'authentication', 'verified', $this->guardCredentials($params), "Laravel's Authenticate middleware: 401 unless the ".(count($params) > 1 ? 'guards '.implode(', ', $params) : 'guard '.($params[0] ?? ($this->defaultGuard ?? 'default'))).' resolve a user', [401], $evidence),
            'basic' => self::result('authenticates', 'authentication', 'verified', ['Authorization: Basic (HTTP basic auth)'], "Laravel's HTTP basic auth", [401], $evidence),
            'token_user' => self::result('authenticates', 'authentication', 'verified', ['an authenticated token user'], 'rejects requests without an authenticated user token, then checks its abilities/scopes', [401, 403], $evidence),
            'client_token' => self::result('authenticates', 'authentication', 'verified', ['Authorization: Bearer <client token>'], 'Passport client-credentials token check', [401], $evidence),
            'authorize' => self::result('none', 'authorization', 'verified', [], 'Gate check ('.implode(', ', $params).'): 403 when denied; guests are denied unless the policy allows them', [403], $evidence),
            'access' => self::result('none', 'access_check', 'verified', [], 'framework access check (403)', [403], $evidence),
            'guest_only' => self::result('none', 'guest_only', 'verified', [], 'only for visitors who are not signed in', [], $evidence),
            'rate_limit' => self::result('none', 'rate_limit', 'verified', [], 'rate limit'.($params === [] ? '' : ' ('.implode(',', $params).')'), [429], $evidence),
            default => self::result('none', 'none', 'verified', [], null, [], $evidence),
        };
    }

    /**
     * @param  list<string>  $params
     * @return list<string>
     */
    private function guardCredentials(array $params): array
    {
        $out = [];
        foreach ($params === [] ? [$this->defaultGuard] : $params as $guard) {
            $driver = $guard === null ? null : ($this->guards[$guard] ?? null);
            $out[] = match ($driver) {
                'passport', 'jwt', 'token' => "Authorization: Bearer <token> (guard {$guard}, {$driver})",
                'sanctum' => "Authorization: Bearer <token>, or the first-party session cookie (guard {$guard}, sanctum)",
                'session' => "session cookie (guard {$guard})",
                null => 'credentials of guard '.($guard ?? 'default').' (its driver could not be read)',
                default => "credentials for guard {$guard} (driver {$driver})",
            };
        }

        return $out;
    }

    private function knownAncestor(ClassInfo $info): ?string
    {
        $current = $info;
        for ($i = 0; $i < 6; $i++) {
            $parent = $current->parentName();
            if ($parent === null) {
                return null;
            }
            if (isset(self::KNOWN[strtolower($parent)])) {
                return self::KNOWN[strtolower($parent)];
            }
            $current = $this->index->find($parent);
            if ($current === null) {
                return null;
            }
        }

        return null;
    }

    private function delegatesToParent(Stmt\ClassMethod $handle): bool
    {
        return (new NodeFinder)->findFirst($handle->stmts ?? [], fn (Node $n) => ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name && strtolower($n->class->toString()) === 'parent'
            && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === 'handle')
            || ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === 'authenticate'
                && $n->var instanceof Expr\Variable && $n->var->name === 'this')) !== null;
    }

    /** @return array{verdict: string, kind: string, status: string, credentials: list<string>, note: string|null, rejects: list<int>, evidence: list<string>} */
    private function paths(ClassInfo $owner, Stmt\ClassMethod $handle): array
    {
        $this->class = $owner;
        $this->vars = [];
        $this->passes = [];
        $this->credentials = [];
        $this->opaque = null;

        $p0 = $handle->params[0]->var ?? null;
        $p1 = $handle->params[1]->var ?? null;
        $this->req = $p0 instanceof Expr\Variable && is_string($p0->name) ? $p0->name : 'request';
        $this->next = $p1 instanceof Expr\Variable && is_string($p1->name) ? $p1->name : 'next';

        $this->collectVars($handle->stmts ?? []);
        $this->walk($handle->stmts ?? [], [[]]);

        $evidence = [$this->evidence->add($owner->file->path, $owner->sha256, $owner->symbol('handle'), $handle, 'middleware')];
        $rejects = $this->rejections($owner, $handle);
        $credentials = array_values(array_unique($this->credentials));

        if ($this->opaque !== null) {
            return self::result('unknown', 'unknown', 'unknown', $credentials, $this->opaque, $rejects, $evidence);
        }
        if ($this->passes === []) {
            return self::result('unknown', 'unknown', 'unknown', $credentials, 'no path to the controller ($next) could be followed', $rejects, $evidence);
        }

        $strengths = array_map(fn (array $facts) => $facts === [] ? 0 : max(array_column($facts, 'rank')), $this->passes);
        $min = min($strengths);
        $max = max($strengths);
        $weakLine = $this->weakestLine($strengths);

        // Paths with no credential proof: can a client reach them just by not sending credentials?
        $weak = array_values(array_filter($this->passes, fn (array $facts) => ($facts === [] ? 0 : max(array_column($facts, 'rank'))) === 0));
        // Guests "just pass" only if each such path was reached because a credential check came out
        // negative, and no other condition (a URL, a value set by earlier middleware) had to hold.
        $guestsPass = $weak !== [] && array_filter($weak, fn (array $facts) => array_filter($facts, fn ($f) => $f['involves']) === []
            || array_filter($facts, fn ($f) => $f['positive'] && ! $f['involves']) !== []) === [];

        [$verdict, $note] = match (true) {
            $min === 3 => ['authenticates', 'every path to the controller verifies a credential'],
            $min === 2 => ['presence_only', "the path at line {$weakLine} lets the credential through without verifying it"],
            $min === 1 && $max >= 2 => ['guest_or_user', "passes with a credential, or with only a request value (path at line {$weakLine})"],
            $min === 0 && $guestsPass => ['optional', 'requests without credentials pass; a user is attached when one is sent'],
            $min === 0 && $max >= 2 => ['conditional', "the path at line {$weakLine} reaches the controller without a credential check, depending on something other than credentials"],
            default => ['none', null],
        };

        if (in_array($verdict, ['none', 'optional'], true) && $max === 0
            && array_intersect($rejects, [401, 403]) !== [] && $this->readsCredential($owner, $handle)) {
            // It refuses requests and touches credentials, but the decision rests on code that can't be followed
            // (e.g. a helper that looks the token up). Never "not required" on that basis.
            return self::result('unknown', 'unknown', 'unknown', $credentials === [] ? ['an authentication credential'] : $credentials,
                'it can refuse the request ('.implode('/', $rejects).') and reads credentials, but the check behind it could not be followed', $rejects, $evidence);
        }

        $kind = match ($verdict) {
            'optional' => 'optional_auth',
            'none' => match (true) {
                in_array(403, $rejects, true) => 'access_check',
                in_array(429, $rejects, true) => 'rate_limit',
                default => 'none',
            },
            default => 'authentication',
        };

        return self::result($verdict, $kind, 'verified', in_array($verdict, ['none', 'optional'], true) ? [] : $credentials, $note, $rejects, $evidence);
    }

    /** Does handle() (or a $this method it calls) read a credential anywhere, even inside unreadable calls? */
    private function readsCredential(ClassInfo $owner, Stmt\ClassMethod $handle): bool
    {
        $nodes = [$handle];
        foreach ((new NodeFinder)->find($handle->stmts ?? [], fn (Node $n) => $n instanceof Expr\MethodCall && $n->var instanceof Expr\Variable
            && $n->var->name === 'this' && $n->name instanceof Node\Identifier) as $call) {
            /** @var Expr\MethodCall $call */
            $found = $call->name instanceof Node\Identifier ? $this->index->method($owner, $call->name->toString()) : null;
            $found !== null && $nodes[] = $found[1];
        }
        $resolver = $owner->file->resolver();

        return (new NodeFinder)->findFirst($nodes, function (Node $n) use ($resolver) {
            if ($n instanceof Expr && $this->isIdentityRead($n)) {
                return true;
            }
            if (! $n instanceof Expr\MethodCall || ! $n->name instanceof Node\Identifier || ! $this->isRequest($n->var)) {
                return false;
            }
            $key = $resolver->arg($n->args, 0);

            return in_array(strtolower($n->name->toString()), ['header', 'hasheader', 'input', 'get', 'query', 'post', 'has', 'filled', 'cookie'], true)
                && is_string($key) && preg_match(self::CREDENTIAL_NAME, $key) === 1;
        }) !== null;
    }

    /** @param list<int> $strengths */
    private function weakestLine(array $strengths): int
    {
        $i = $strengths === [] ? false : array_search(min($strengths), $strengths, true);
        $facts = $this->passes[$i === false ? 0 : $i];

        return $facts === [] ? $this->class->node->getStartLine() : max(array_column($facts, 'line'));
    }

    /**
     * Follows statements in order. A state is the list of facts (conditions known to hold) on one
     * path; returns the states that fall through to the end of $stmts.
     *
     * @param  array<Node>  $stmts
     * @param  list<list<array{rank: int, line: int, involves: bool, positive: bool}>>  $states
     * @return list<list<array{rank: int, line: int, involves: bool, positive: bool}>>
     */
    private function walk(array $stmts, array $states): array
    {
        foreach ($stmts as $stmt) {
            if ($states === [] || $this->opaque !== null) {
                return [];
            }
            $next = [];
            foreach ($states as $state) {
                array_push($next, ...$this->step($stmt, $state));
            }
            $states = $next;
        }

        return $states;
    }

    /**
     * @param  list<array{rank: int, line: int, involves: bool, positive: bool}>  $state
     * @return list<list<array{rank: int, line: int, involves: bool, positive: bool}>>
     */
    private function step(Node $stmt, array $state): array
    {
        $callsNext = $this->callsNext($stmt);

        if ($stmt instanceof Stmt\Return_ || $stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\Throw_) {
            if ($callsNext) {
                $expr = $stmt instanceof Stmt\Return_ ? $stmt->expr : null;
                if ($expr instanceof Expr\Ternary) {
                    // return $ok ? $next($request) : response(…, 401)
                    $inIf = $expr->if !== null && $this->callsNext($expr->if);
                    $inElse = $this->callsNext($expr->else);
                    $inIf && $this->passes[] = [...$state, $this->fact($expr->cond, true)];
                    $inElse && $this->passes[] = [...$state, $this->fact($expr->cond, false)];
                    ! $inIf && ! $inElse && $this->passes[] = $state;
                } else {
                    $this->passes[] = $state;
                }
            }

            return []; // the path ends here: either it reached the controller, or it answered itself
        }

        if ($stmt instanceof Stmt\If_) {
            $out = [];
            $falses = [];
            $branches = [[$stmt->cond, $stmt->stmts]];
            foreach ($stmt->elseifs as $elseif) {
                $branches[] = [$elseif->cond, $elseif->stmts];
            }
            foreach ($branches as [$cond, $body]) {
                // Inside this branch: its condition is true and every earlier one was false.
                array_push($out, ...$this->walk($body, [[...$state, ...$falses, $this->fact($cond, true)]]));
                $falses[] = $this->fact($cond, false);
            }
            array_push($out, ...$this->walk($stmt->else->stmts ?? [], [[...$state, ...$falses]]));

            return $out;
        }

        if ($stmt instanceof Stmt\Expression && $stmt->expr instanceof Expr\FuncCall && $stmt->expr->name instanceof Node\Name) {
            $fn = strtolower($stmt->expr->name->toString());
            $cond = $stmt->expr->args[0] ?? null;
            if (in_array($fn, ['abort_if', 'abort_unless'], true) && $cond instanceof Node\Arg) {
                // Execution continues only when abort_if's condition is false (abort_unless: true).
                return [[...$state, $this->fact($cond->value, $fn === 'abort_unless')]];
            }
            if ($fn === 'abort') {
                return [];
            }
        }

        if ($stmt instanceof Stmt\TryCatch) {
            if ($stmt->catches !== [] && (new NodeFinder)->findFirst($stmt->catches, fn (Node $n) => $this->isNextCall($n)) !== null) {
                $this->opaque = "a catch block at line {$stmt->getStartLine()} continues to the controller";

                return [];
            }

            return $this->walk($stmt->stmts, [$state]);
        }

        if ($callsNext) {
            if ($stmt instanceof Stmt\Expression) {
                $this->passes[] = $state; // $response = $next($request); …

                return [$state];
            }
            $this->opaque = 'it reaches the controller from inside a loop or switch (line '.$stmt->getStartLine().')';

            return [];
        }

        return [$state];
    }

    private function callsNext(Node $stmt): bool
    {
        return (new NodeFinder)->findFirst([$stmt], fn (Node $n) => $this->isNextCall($n)) !== null;
    }

    private function isNextCall(Node $n): bool
    {
        return ($n instanceof Expr\FuncCall && $n->name instanceof Expr\Variable && $n->name->name === $this->next)
            || ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name && strtolower($n->class->toString()) === 'parent'
                && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === 'handle');
    }

    /** @return array{rank: int, line: int, involves: bool, positive: bool} */
    private function fact(Expr $cond, bool $truth): array
    {
        return [
            'rank' => $this->holds($cond, $truth),
            'line' => $cond->getStartLine(),
            // Does the condition look at credentials at all (whichever way it went)?
            'involves' => $this->holds($cond, true) >= 2 || $this->holds($cond, false) >= 2,
            // A branch entered because its condition held (a gate), vs one skipped because it didn't.
            'positive' => $truth,
        ];
    }

    /**
     * What we learn about credentials when $cond evaluates to $truth: 3 = a credential was
     * verified, 2 = a credential is present, 1 = a plain request value is present, 0 = nothing.
     * Negations, && and || are followed, so "auth()->check() is false" never counts as a check.
     */
    private function holds(Expr $cond, bool $truth): int
    {
        if ($cond instanceof Expr\BooleanNot) {
            return $this->holds($cond->expr, ! $truth);
        }
        if ($cond instanceof Expr\BinaryOp\BooleanAnd || $cond instanceof Expr\BinaryOp\LogicalAnd) {
            return $truth ? max($this->holds($cond->left, true), $this->holds($cond->right, true))
                : min($this->holds($cond->left, false), $this->holds($cond->right, false));
        }
        if ($cond instanceof Expr\BinaryOp\BooleanOr || $cond instanceof Expr\BinaryOp\LogicalOr) {
            return $truth ? min($this->holds($cond->left, true), $this->holds($cond->right, true))
                : max($this->holds($cond->left, false), $this->holds($cond->right, false));
        }
        if ($cond instanceof Expr\Assign) {
            return $truth ? $this->value($cond->expr) : 0; // if (! $user = auth()->user())
        }
        if ($cond instanceof Expr\Isset_) {
            return $truth && $cond->vars !== [] ? min(array_map(fn ($v) => $this->value($v), $cond->vars)) : 0;
        }
        if ($cond instanceof Expr\Empty_) {
            return $truth ? 0 : $this->value($cond->expr);
        }
        if ($cond instanceof Expr\FuncCall && $cond->name instanceof Node\Name && strtolower($cond->name->toString()) === 'is_null' && isset($cond->args[0]) && $cond->args[0] instanceof Node\Arg) {
            return $truth ? 0 : $this->value($cond->args[0]->value);
        }
        if ($cond instanceof Expr\BinaryOp && ! $cond instanceof Expr\BinaryOp\Concat && ! $cond instanceof Expr\BinaryOp\Coalesce) {
            return $this->comparison($cond, $truth);
        }
        if ($cond instanceof Expr\MethodCall && $cond->name instanceof Node\Identifier) {
            $m = strtolower($cond->name->toString());
            // $validator->fails() / ->passes(), auth()->guest(), $query->doesntExist(), $request->missing('x')
            if (in_array($m, ['fails', 'passes'], true)) {
                return ($m === 'passes') === $truth ? $this->validatorRank($cond->var) : 0;
            }
            if ($m === 'guest' && $this->isGuard($cond->var)) {
                return $truth ? 0 : $this->value(new Expr\MethodCall($cond->var, 'check'));
            }
            if (in_array($m, ['doesntexist', 'missing'], true)) {
                return $truth ? 0 : $this->value(new Expr\MethodCall($cond->var, $m === 'missing' ? 'has' : 'exists', $cond->args));
            }
        }

        return $truth ? $this->value($cond) : 0;
    }

    private function comparison(Expr\BinaryOp $cond, bool $truth): int
    {
        $resolver = $this->class->file->resolver();
        $left = $cond->left;
        $right = $cond->right;
        $rv = $resolver->resolve($right);
        $lv = $resolver->resolve($left);
        [$subject, $literal] = ! ValueResolver::isDynamic($rv) && ! is_array($rv) ? [$left, $rv] : (! ValueResolver::isDynamic($lv) && ! is_array($lv) ? [$right, $lv] : [null, null]);

        if ($subject === null) {
            // Two computed sides, e.g. $token->secret === hash('sha256', $secret): equal only if both exist.
            $equal = $cond instanceof Expr\BinaryOp\Identical || $cond instanceof Expr\BinaryOp\Equal;
            $notEqual = $cond instanceof Expr\BinaryOp\NotIdentical || $cond instanceof Expr\BinaryOp\NotEqual;
            if (($equal && $truth) || ($notEqual && ! $truth)) {
                $a = $this->value($left);
                $b = $this->value($right);

                return max($a, $b) === 3 && min($a, $b) >= 2 ? 3 : min(2, max($a, $b));
            }

            return 0;
        }

        $value = $this->value($subject);
        $empty = $literal === null || $literal === '' || $literal === 0 || $literal === false || $literal === '0';
        $one = $literal === 1;

        // "subject is present" when the comparison has $truth
        $present = match (true) {
            $cond instanceof Expr\BinaryOp\Identical, $cond instanceof Expr\BinaryOp\Equal => $empty ? ! $truth : $truth,
            $cond instanceof Expr\BinaryOp\NotIdentical, $cond instanceof Expr\BinaryOp\NotEqual => $empty ? $truth : ! $truth,
            $cond instanceof Expr\BinaryOp\Smaller => ($empty || $one) && ! $truth,            // strlen($t) < 1 is false
            $cond instanceof Expr\BinaryOp\SmallerOrEqual => $empty && ! $truth,
            $cond instanceof Expr\BinaryOp\Greater => $empty && $truth,                        // strlen($t) > 0
            $cond instanceof Expr\BinaryOp\GreaterOrEqual => $one && $truth,
            default => false,
        };

        // Equal to a fixed non-empty value proves presence, not verification.
        return $present ? ($empty ? $value : min(2, $value)) : 0;
    }

    private function validatorRank(Expr $validator): int
    {
        if ($validator instanceof Expr\Variable && is_string($validator->name)) {
            array_push($this->credentials, ...($this->vars[$validator->name]['credentials'] ?? []));

            return $this->vars[$validator->name]['validator'] ?? 0;
        }

        return $this->validatorMake($validator)['rank'];
    }

    /**
     * What an expression's value proves when it is truthy (present / non-null).
     */
    private function value(Expr $expr, int $depth = 0): int
    {
        return $this->classify($expr, $depth)['rank'];
    }

    /**
     * What a value proves when truthy, and which credentials it reads.
     *
     * @return array{rank: int, credentials: list<string>}
     */
    private function classify(Expr $n, int $depth = 0): array
    {
        $resolver = $this->class->file->resolver();
        $out = fn (int $rank, ?string $credential = null) => ['rank' => $rank, 'credentials' => $credential === null ? [] : [$credential]];
        $result = null;

        if ($n instanceof Expr\Assign) {
            $result = $this->classify($n->expr, $depth);
        } elseif ($n instanceof Expr\Variable && is_string($n->name)) {
            $result = isset($this->vars[$n->name]) ? ['rank' => $this->vars[$n->name]['rank'], 'credentials' => $this->vars[$n->name]['credentials']] : $out(0);
        } elseif ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && $this->isRequest($n->var)) {
            $m = strtolower($n->name->toString());
            $arg = $resolver->arg($n->args, 0);
            $key = is_string($arg) ? $arg : null;
            $result = match (true) {
                $m === 'bearertoken' => $out(2, 'Authorization: Bearer <token>'),
                $m === 'user' => $out(3, 'an authenticated user'),
                // Only Authorization or a credential-named header (X-API-Secret, X-Auth-Token) is a credential;
                // moduleId, zoneId, vendorType … are plain request values.
                in_array($m, ['header', 'hasheader'], true) => $key !== null && (strcasecmp($key, 'Authorization') === 0 || preg_match(self::CREDENTIAL_NAME, $key))
                    ? $out(2, strcasecmp($key, 'Authorization') === 0 ? 'Authorization header' : "header {$key}")
                    : $out(1, $key === null ? null : "header {$key}"),
                in_array($m, ['input', 'get', 'query', 'post', 'has', 'filled', 'string', 'str', 'integer', 'boolean', 'cookie'], true) => $key !== null && preg_match(self::CREDENTIAL_NAME, $key)
                    ? $out(2, "request value {$key}") : $out(1, $key === null ? null : "request value {$key}"),
                default => $out(0),
            };
        } elseif (($n instanceof Expr\PropertyFetch || $n instanceof Expr\NullsafePropertyFetch) && $n->name instanceof Node\Identifier && $this->isRequest($n->var)) {
            $key = $n->name->toString();
            $result = preg_match(self::CREDENTIAL_NAME, $key) ? $out(2, "request value {$key}") : $out(1, "request value {$key}");
        } elseif ($n instanceof Expr\ArrayDimFetch && $n->dim !== null && $this->isRequest($n->var)) {
            $key = $resolver->resolve($n->dim);
            $result = is_string($key) ? (preg_match(self::CREDENTIAL_NAME, $key) ? $out(2, "request value {$key}") : $out(1, "request value {$key}")) : $out(0);
        } elseif ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && $this->isGuard($n->var)) {
            // auth()->user(), Auth::guard('api')->check(), auth('api')->id(); ->guard() alone is just an object.
            $result = in_array(strtolower($n->name->toString()), self::GUARD_CHECKS, true) && strtolower($n->name->toString()) !== 'guest'
                ? $out(3, 'an authenticated user ('.($this->guardName($n->var) ?? 'default guard').')') : $out(0);
        } elseif ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name && $n->name instanceof Node\Identifier && $this->isFacade($n->class, 'auth')) {
            $m = strtolower($n->name->toString());
            $result = in_array($m, self::GUARD_CHECKS, true) && $m !== 'guest' ? $out(3, 'an authenticated user (default guard)') : $out(0);
        } elseif ($n instanceof Expr\FuncCall && $n->name instanceof Node\Name && in_array(strtolower($n->name->toString()), ['hash_equals', 'password_verify'], true)) {
            $result = $out($this->involvesCredential($n) ? 3 : 0);
        } elseif ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name && $this->isFacade($n->class, 'hash') && $n->name instanceof Node\Identifier && strtolower($n->name->toString()) === 'check') {
            $result = $out($this->involvesCredential($n) ? 3 : 0);
        } elseif ($n instanceof Expr\MethodCall && $n->name instanceof Node\Identifier && in_array(strtolower($n->name->toString()), self::LOOKUP_ENDS, true) && $this->isLookup($n)) {
            // Vendor::where('auth_token', $token)->first(): a credential verified against stored data
            $result = $out($this->involvesCredential($n) ? 3 : 0);
        } elseif ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name && $this->isFacade($n->class, 'validator')) {
            $result = $out(0); // a validator object is always truthy; only ->fails()/->passes() say something
        } elseif ($n instanceof Expr\MethodCall && $n->var instanceof Expr\Variable && $n->var->name === 'this' && $n->name instanceof Node\Identifier) {
            // $this->resolveUser($request): what it returns, when every return proves the same.
            $found = $depth < 1 ? $this->index->method($this->class, $n->name->toString()) : null;
            $ranks = [];
            $credentials = [];
            foreach ($found === null ? [] : (new NodeFinder)->findInstanceOf($found[1]->stmts ?? [], Stmt\Return_::class) as $return) {
                $inner = $return->expr === null ? ['rank' => 0, 'credentials' => []] : $this->classify($return->expr, $depth + 1);
                $ranks[] = $inner['rank'];
                array_push($credentials, ...$inner['credentials']);
            }
            $result = ['rank' => $ranks === [] ? 0 : min($ranks), 'credentials' => $credentials];
        } elseif ($this->transparent($n)) {
            // strlen($token), trim($header), $vendor->id …: truthy only if what it wraps is present.
            $rank = 0;
            $credentials = [];
            foreach ($n->getSubNodeNames() as $name) {
                $children = is_array($n->$name) ? $n->$name : [$n->$name];
                foreach ($children as $child) {
                    $child = $child instanceof Node\Arg ? $child->value : $child;
                    if ($child instanceof Expr && ! $child instanceof Expr\Closure && ! $child instanceof Expr\ArrowFunction) {
                        $inner = $this->classify($child, $depth);
                        $rank = max($rank, $inner['rank']);
                        array_push($credentials, ...$inner['credentials']);
                    }
                }
            }
            $isRecordAccess = ($n instanceof Expr\PropertyFetch || $n instanceof Expr\NullsafePropertyFetch) && $n->var instanceof Expr\Variable;
            $result = ['rank' => $isRecordAccess ? $rank : min(2, $rank), 'credentials' => $credentials];
        } else {
            // Any other call (RateLimiter::tooManyAttempts($key), cache()->has(…)) says nothing about credentials,
            // even when its arguments were derived from one.
            $result = $out(0);
        }

        array_push($this->credentials, ...$result['credentials']);

        return ['rank' => $result['rank'], 'credentials' => array_values(array_unique($result['credentials']))];
    }

    /** Operations whose result is present only when their input is: casts, property/array access, string helpers. */
    private function transparent(Expr $n): bool
    {
        if ($n instanceof Expr\Cast || $n instanceof Expr\PropertyFetch || $n instanceof Expr\NullsafePropertyFetch || $n instanceof Expr\ArrayDimFetch
            || $n instanceof Expr\Ternary || $n instanceof Expr\BinaryOp\Coalesce || $n instanceof Expr\ErrorSuppress) {
            return true;
        }
        if ($n instanceof Expr\FuncCall && $n->name instanceof Node\Name) {
            return in_array(strtolower($n->name->toString()), ['strlen', 'mb_strlen', 'trim', 'ltrim', 'rtrim', 'strtolower', 'strtoupper', 'str_replace', 'substr', 'explode',
                'base64_decode', 'urldecode', 'rawurldecode', 'preg_replace', 'strval', 'filled', 'count', 'optional', 'data_get'], true);
        }
        if ($n instanceof Expr\StaticCall && $n->class instanceof Node\Name) {
            return str_ends_with(strtolower(ClassInfo::name($n->class)), '\\str') || strtolower(ClassInfo::name($n->class)) === 'str';
        }

        return false;
    }

    /**
     * Validator::make($request->all(), ['token' => 'required|exists:…']): what passing it proves.
     *
     * @return array{rank: int, credentials: list<string>}
     */
    private function validatorMake(Expr $expr): array
    {
        while ($expr instanceof Expr\MethodCall) {
            $expr = $expr->var;
        }
        if (! $expr instanceof Expr\StaticCall || ! $expr->class instanceof Node\Name || ! $this->isFacade($expr->class, 'validator')) {
            return ['rank' => 0, 'credentials' => []];
        }

        $rules = $this->class->file->resolver()->arg($expr->args, 1);
        $rank = 0;
        $credentials = [];
        foreach (is_array($rules) ? $rules : [] as $field => $rule) {
            $text = is_array($rule) ? implode('|', array_filter($rule, 'is_string')) : (is_string($rule) ? $rule : '');
            $credential = preg_match(self::CREDENTIAL_NAME, (string) $field) === 1;
            if (str_contains($text, 'exists:') && $credential) {
                $rank = max($rank, 3);
                $credentials[] = "request value {$field} (must exist: ".self::existsRule($text).')';
            } elseif (str_contains($text, 'required')) {
                $rank = max($rank, $credential ? 2 : 1);
                $credentials[] = "request value {$field}";
            }
        }

        return ['rank' => $rank, 'credentials' => $credentials];
    }

    private static function existsRule(string $rules): string
    {
        foreach (explode('|', $rules) as $rule) {
            if (str_starts_with($rule, 'exists:')) {
                return substr($rule, 7);
            }
        }

        return '';
    }

    /**
     * Assignments in handle(), flow-insensitively, in source order; a variable assigned several
     * ways keeps its weakest meaning.
     *
     * @param  array<Node>  $stmts
     */
    private function collectVars(array $stmts): void
    {
        foreach ((new NodeFinder)->findInstanceOf($stmts, Expr\Assign::class) as $assign) {
            if (! $assign->var instanceof Expr\Variable || ! is_string($assign->var->name)) {
                continue;
            }
            $saved = $this->credentials;
            $c = $this->classify($assign->expr);
            $v = $this->validatorMake($assign->expr);
            $this->credentials = $saved; // a read only counts once a path's condition uses it
            $name = $assign->var->name;
            $this->vars[$name] = isset($this->vars[$name])
                ? [
                    'rank' => min($this->vars[$name]['rank'], $c['rank']),
                    'validator' => min($this->vars[$name]['validator'], $v['rank']),
                    'credentials' => array_values(array_unique([...$this->vars[$name]['credentials'], ...$c['credentials'], ...$v['credentials']])),
                ]
                : ['rank' => $c['rank'], 'validator' => $v['rank'], 'credentials' => [...$c['credentials'], ...$v['credentials']]];
        }
    }

    private function involvesCredential(Node $node, int $depth = 0): bool
    {
        foreach ((new NodeFinder)->find([$node], fn (Node $n) => $n instanceof Node\Arg) as $arg) {
            /** @var Node\Arg $arg */
            if ($arg->value instanceof Expr\Closure || $arg->value instanceof Expr\ArrowFunction) {
                continue;
            }
            $saved = $this->credentials;
            $rank = $this->classifyShallow($arg->value, $depth);
            $this->credentials = $saved;
            if ($rank >= 2) {
                return true;
            }
        }

        return false;
    }

    /** A credential read (or a variable holding one) without counting lookups, to avoid recursion loops. */
    private function classifyShallow(Expr $expr, int $depth): int
    {
        $rank = 0;
        foreach ((new NodeFinder)->find([$expr], fn (Node $n) => $n instanceof Expr) as $n) {
            if ($n instanceof Expr\Variable && is_string($n->name) && isset($this->vars[$n->name])) {
                $rank = max($rank, min(2, $this->vars[$n->name]['rank']));
            } elseif (($n instanceof Expr\MethodCall || $n instanceof Expr\PropertyFetch || $n instanceof Expr\ArrayDimFetch) && $this->isRequest($n->var)) {
                $rank = max($rank, $this->classify($n, $depth + 1)['rank']);
            }
        }

        return $rank;
    }

    /** A query chain on a model or DB::table(): Model::where(...)->first(), DB::table(...)->where(...)->exists() */
    private function isLookup(Expr\MethodCall $call): bool
    {
        $expr = $call->var;
        while ($expr instanceof Expr\MethodCall) {
            $expr = $expr->var;
        }

        if (! $expr instanceof Expr\StaticCall || ! $expr->class instanceof Node\Name) {
            return false;
        }
        $class = strtolower(ClassInfo::name($expr->class));

        return $class === 'illuminate\support\facades\db' || $class === 'db' || ! str_starts_with($class, 'illuminate\\');
    }

    private function isGuard(Expr $expr): bool
    {
        // auth(), auth('api'), Auth::guard('api'), app('auth')->guard('api'), auth()->guard('api')
        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && strtolower($expr->name->toString()) === 'auth') {
            return true;
        }
        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $this->isFacade($expr->class, 'auth')) {
            return true;
        }
        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'guard') {
            return $this->isGuard($expr->var) || ($expr->var instanceof Expr\FuncCall && $expr->var->name instanceof Node\Name
                && strtolower($expr->var->name->toString()) === 'app' && $this->class->file->resolver()->arg($expr->var->args, 0) === 'auth');
        }

        return false;
    }

    private function guardName(Expr $expr): ?string
    {
        $resolver = $this->class->file->resolver();
        if ($expr instanceof Expr\FuncCall || ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier && strtolower($expr->name->toString()) === 'guard')
            || $expr instanceof Expr\StaticCall) {
            $name = $resolver->arg($expr->args, 0);

            return is_string($name) ? "guard {$name}" : null;
        }

        return null;
    }

    private function isFacade(Node\Name $name, string $facade): bool
    {
        $resolved = strtolower(ClassInfo::name($name));

        return $resolved === $facade || $resolved === 'illuminate\support\facades\\'.$facade || str_ends_with($resolved, '\\'.$facade);
    }

    private function isRequest(Expr $expr): bool
    {
        return ($expr instanceof Expr\Variable && $expr->name === $this->req)
            || ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && strtolower($expr->name->toString()) === 'request' && $expr->args === []);
    }

    /** @return list<int> statuses this middleware answers with: 401, 403, 429 … (handle() and $this methods it calls) */
    private function rejections(ClassInfo $owner, Stmt\ClassMethod $handle): array
    {
        $nodes = [$handle];
        foreach ((new NodeFinder)->find($handle->stmts ?? [], fn (Node $n) => $n instanceof Expr\MethodCall && $n->var instanceof Expr\Variable
            && $n->var->name === 'this' && $n->name instanceof Node\Identifier) as $call) {
            /** @var Expr\MethodCall $call */
            $found = $call->name instanceof Node\Identifier ? $this->index->method($owner, $call->name->toString()) : null;
            $found !== null && $nodes[] = $found[1];
        }

        $codes = [];
        foreach ((new NodeFinder)->find($nodes, fn (Node $n) => $n instanceof Scalar\Int_ || $n instanceof Expr\ClassConstFetch || $n instanceof Expr\New_) as $n) {
            if ($n instanceof Scalar\Int_ && in_array($n->value, [401, 403, 419, 429], true)) {
                $codes[] = $n->value;
            } elseif ($n instanceof Expr\ClassConstFetch && $n->name instanceof Node\Identifier) {
                $code = ['HTTP_UNAUTHORIZED' => 401, 'HTTP_FORBIDDEN' => 403, 'HTTP_TOO_MANY_REQUESTS' => 429][$n->name->toString()] ?? null;
                $code !== null && $codes[] = $code;
            } elseif ($n instanceof Expr\New_ && $n->class instanceof Node\Name) {
                $base = strtolower($n->class->getLast());
                in_array($base, ['authenticationexception', 'unauthorizedhttpexception'], true) && $codes[] = 401;
                in_array($base, ['authorizationexception', 'accessdeniedhttpexception'], true) && $codes[] = 403;
            }
        }
        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * @param  list<string>  $credentials
     * @param  list<int>  $rejects
     * @param  list<string>  $evidence
     * @return array{verdict: string, kind: string, status: string, credentials: list<string>, note: string|null, rejects: list<int>, evidence: list<string>}
     */
    private static function result(string $verdict, string $kind, string $status, array $credentials, ?string $note, array $rejects, array $evidence): array
    {
        return compact('verdict', 'kind', 'status', 'credentials', 'note', 'rejects', 'evidence');
    }
}
