# Desenvolvimento modular na prática

O template usa Laravel 13, Eloquent e CQRS leve: uma escrita tem um comando imutável e um handler dedicado; uma consulta retorna uma projeção sem modificar dados. O controller chama o handler ou a API diretamente pelo container. Não há bus obrigatório, agregado artificial ou repository genérico.

## Comece pelo fluxo existente

Em Teams, siga `CreateTeamInvitationRequest` → `TeamInvitationController` → `CreateTeamInvitation` → `CreateTeamInvitationHandler`. `InvitationPolicy` decide papel, destinatário e expiração; os adaptadores próprios persistem dentro da transação, e `TeamInvitationSender` despacha a notificação com `afterCommit()`. A leitura usa `TeamQueries::editFor`, verifica membership e projeta dados sem escrever ou selecionar outra equipe. Os testes estão em `tests/Unit/Teams`, `tests/Integration/Teams`, `tests/Feature/Teams` e `tests/Concurrency`.

Domain contém PHP puro. Application não importa Laravel, Eloquent, Infrastructure ou Presentation. Infrastructure implementa as portas que realmente precisam de persistência/efeitos. Presentation cuida de autenticação, autorização, validação HTTP e respostas. `Application/Api` exporta contratos e dados imutáveis; outro módulo não importa models, handlers ou portas internas. Os consumidores de APIs restritas são explícitos em `tests/Architecture/Support/ModuleBoundaryInspector.php`: por exemplo, `Identity/UserDirectory` só é autorizado para Teams e Profile. Expor uma interface não autoriza qualquer consumidor.

SOLID aqui significa dependências explícitas e responsabilidades pequenas. KISS/DRY/YAGNI significam reutilizar regras usadas, acrescentar uma porta numa fronteira necessária e evitar uma camada vazia ou abstração sem consumidor. Consulte [o contrato completo](../ARCHITECTURE.md) para ownership, transações e limites dos checks estáticos.

## Exemplo executável: ReferenceCatalog

Faça o exercício numa cópia descartável do template já instalada, com `.env` próprio, SQLite próprio e `APP_ENV=local`. Não execute o exercício sobre dados de uma aplicação existente. O exemplo é um catálogo **compartilhado** de etiquetas: qualquer conta autenticada pode criar e consultar nomes comuns. Não representa isolamento por usuário/tenant. Nome normalizado é sua chave natural; repetir uma criação devolve a mesma etiqueta.

O exemplo não faz parte dos módulos distribuídos: os arquivos abaixo são criados apenas quando você segue o tutorial. Os comandos usam os geradores implementados; consulte `php artisan list` e `php artisan <comando> --help` antes de acrescentar opções.

### 1. Escreva o teste antes do módulo

```bash
php artisan make:test --pest ReferenceCatalogLabelsTest --no-interaction
```

Substitua `tests/Feature/ReferenceCatalogLabelsTest.php` pelo conteúdo abaixo. As factories de Identity são usadas pela **composição do teste**, não por outro módulo de produção.

```php
<?php

use App\Modules\Identity\Infrastructure\Persistence\Eloquent\Models\User;
use App\Modules\ReferenceCatalog\Application\Api\Labels;
use App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\Models\Label;
use App\Modules\ReferenceCatalog\Infrastructure\Persistence\Seeders\ReferenceCatalogLabelsSeeder;

test('guests cannot read the shared label catalog', function (): void {
    $this->getJson(route('catalog.labels.index'))->assertUnauthorized();
});

test('guests cannot create a catalog label', function (): void {
    $this->postJson(route('catalog.labels.store'), ['name' => 'Design'])
        ->assertUnauthorized();
    $this->assertDatabaseCount('doc_example_labels', 0);
});

test('authenticated creation normalizes a catalog label', function (): void {
    $this->actingAs(User::factory()->create());

    $response = $this->postJson(route('catalog.labels.store'), ['name' => '  Design  ']);

    $response->assertCreated()->assertJsonStructure(['id']);
    $this->assertDatabaseHas('doc_example_labels', ['name' => 'Design']);
    $this->assertDatabaseCount('doc_example_labels', 1);
});

test('the query projects labels without changing the catalog', function (): void {
    $this->actingAs(User::factory()->create());
    $label = Label::factory()->create(['name' => 'Architecture']);

    $this->getJson(route('catalog.labels.index'))->assertOk()
        ->assertExactJson([['id' => $label->id, 'name' => 'Architecture']]);
    $this->assertDatabaseCount('doc_example_labels', 1);
});

test('the public write API rejects invalid names before persistence', function (string $name): void {
    expect(fn () => app(Labels::class)->create($name))
        ->toThrow(InvalidArgumentException::class);
    $this->assertDatabaseCount('doc_example_labels', 0);
})->with(['blank' => [' '], 'too long' => [str_repeat('x', 81)]]);

test('the explicit local seeder is safe to repeat', function (): void {
    $this->seed(ReferenceCatalogLabelsSeeder::class);
    $this->seed(ReferenceCatalogLabelsSeeder::class);

    $this->assertDatabaseHas('doc_example_labels', ['name' => 'Example label']);
    $this->assertDatabaseCount('doc_example_labels', 1);
});
```

```bash
php artisan test --compact tests/Feature/ReferenceCatalogLabelsTest.php
```

Esse primeiro teste deve ficar vermelho: as rotas e o módulo ainda não existem. Não substitua as expectativas por `assertTrue(true)` nem deixe um `todo()` como resultado final.

### 2. Gere os arquivos com os comandos existentes

```bash
php artisan make:module ReferenceCatalog --no-interaction
php artisan make:module-usecase ReferenceCatalog CreateLabel --no-interaction
php artisan make:module-query ReferenceCatalog ListLabels --contract --no-interaction
php artisan make:module-artifact ReferenceCatalog model Label --factory --no-interaction
php artisan make:module-artifact ReferenceCatalog controller LabelController --no-interaction
php artisan make:module-artifact ReferenceCatalog request StoreLabelRequest --no-interaction
php artisan make:module-artifact ReferenceCatalog migration create_doc_example_labels_table --no-interaction
php artisan make:module-seeder ReferenceCatalog ReferenceCatalogLabelsSeeder --no-interaction
php artisan make:class 'App\Modules\ReferenceCatalog\Domain\Policies\LabelName' --no-interaction
php artisan make:interface 'App\Modules\ReferenceCatalog\Application\Api\Labels' --no-interaction
php artisan make:interface 'App\Modules\ReferenceCatalog\Application\Contracts\StoresLabels' --no-interaction
php artisan make:class 'App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\EloquentLabelStore' --no-interaction
```

`make:module` cria provider, routes e migrations locais e registra o provider em `bootstrap/providers.php`. Não cria uma árvore de camadas vazias. Os geradores recusam nomes inválidos e colisões; não sobrescrevem implementação existente. `--test` em usecase/query cria um teste Pest **pendente**, que você precisa implementar. Handlers/queries gerados lançam `LogicException` até serem implementados.

### 3. Implemente as regras e o contrato de escrita

`app/Modules/ReferenceCatalog/Domain/Policies/LabelName.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Domain\Policies;

use InvalidArgumentException;

class LabelName
{
    public function normalize(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new InvalidArgumentException('Use a label name with 1 to 80 characters.');
        }

        return $name;
    }
}
```

`app/Modules/ReferenceCatalog/Application/Commands/CreateLabel.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Application\Commands;

readonly class CreateLabel
{
    public function __construct(public string $name) {}
}
```

`app/Modules/ReferenceCatalog/Application/Api/Labels.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Application\Api;

/** Trusted integration; the HTTP caller authenticates its executor. */
interface Labels
{
    public function create(string $name): int;
}
```

`app/Modules/ReferenceCatalog/Application/Contracts/StoresLabels.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Application\Contracts;

interface StoresLabels
{
    public function store(string $name): int;
}
```

`app/Modules/ReferenceCatalog/Application/Commands/CreateLabelHandler.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Application\Commands;

use App\Modules\ReferenceCatalog\Application\Api\Labels;
use App\Modules\ReferenceCatalog\Application\Contracts\StoresLabels;
use App\Modules\ReferenceCatalog\Domain\Policies\LabelName;

class CreateLabelHandler implements Labels
{
    public function __construct(private StoresLabels $labels, private LabelName $names) {}

    public function create(string $name): int
    {
        return $this->handle(new CreateLabel($name));
    }

    public function handle(CreateLabel $command): int
    {
        return $this->labels->store($this->names->normalize($command->name));
    }
}
```

A porta tem uma operação concreta: persistir uma etiqueta validada sem expor Eloquent à Application. Uma inserção por chave natural não exige um bus, transaction manager próprio ou aggregate/repository genérico.

### 4. Implemente persistência, factory e consulta

`app/Modules/ReferenceCatalog/Infrastructure/Persistence/Eloquent/Models/Label.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\Models;

use App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\Factories\LabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 */
#[Fillable(['name'])]
class Label extends Model
{
    /** @use HasFactory<LabelFactory> */
    use HasFactory;

    protected $table = 'doc_example_labels';

    protected static function newFactory(): LabelFactory
    {
        return LabelFactory::new();
    }
}
```

`app/Modules/ReferenceCatalog/Infrastructure/Persistence/Eloquent/Factories/LabelFactory.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\Factories;

use App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\Models\Label;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Label> */
class LabelFactory extends Factory
{
    protected $model = Label::class;

    /** @return array{name: string} */
    public function definition(): array
    {
        return ['name' => fake()->unique()->lexify('label-????????')];
    }
}
```

`app/Modules/ReferenceCatalog/Infrastructure/Persistence/Eloquent/EloquentLabelStore.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent;

use App\Modules\ReferenceCatalog\Application\Contracts\StoresLabels;
use App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\Models\Label;

class EloquentLabelStore implements StoresLabels
{
    public function store(string $name): int
    {
        return (int) Label::query()->firstOrCreate(['name' => $name])->getKey();
    }
}
```

`app/Modules/ReferenceCatalog/Application/Data/ListLabelsData.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Application\Data;

readonly class ListLabelsData
{
    /** @param list<array{id: int, name: string}> $labels */
    public function __construct(public array $labels) {}
}
```

Mantenha a interface `Application/Contracts/ListLabelsQuery.php` gerada: `handle(): ListLabelsData`. Substitua a implementação `app/Modules/ReferenceCatalog/Infrastructure/Queries/ListLabels.php`:

```php
<?php

namespace App\Modules\ReferenceCatalog\Infrastructure\Queries;

use App\Modules\ReferenceCatalog\Application\Contracts\ListLabelsQuery;
use App\Modules\ReferenceCatalog\Application\Data\ListLabelsData;
use App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\Models\Label;

class ListLabels implements ListLabelsQuery
{
    public function handle(): ListLabelsData
    {
        $labels = [];
        foreach (Label::query()->orderByDesc('id')->limit(10)->get() as $label) {
            $labels[] = ['id' => $label->id, 'name' => $label->name];
        }

        return new ListLabelsData($labels);
    }
}
```

A consulta é limitada às dez etiquetas mais recentes e não retorna models ou collections do framework. Ela permanece uma porta **interna**; exporte uma API de leitura somente quando existir um consumidor real.

Edite o arquivo com timestamp criado em `app/Modules/ReferenceCatalog/database/migrations/*_create_doc_example_labels_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_example_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_example_labels');
    }
};
```

### 5. Ligue Presentation e o provider

`app/Modules/ReferenceCatalog/Presentation/Http/Requests/StoreLabelRequest.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLabelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:80']];
    }
}
```

`app/Modules/ReferenceCatalog/Presentation/Http/Controllers/LabelController.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Presentation\Http\Controllers;

use App\Modules\ReferenceCatalog\Application\Api\Labels;
use App\Modules\ReferenceCatalog\Application\Contracts\ListLabelsQuery;
use App\Modules\ReferenceCatalog\Presentation\Http\Requests\StoreLabelRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class LabelController
{
    public function index(ListLabelsQuery $query): JsonResponse
    {
        return response()->json($query->handle()->labels);
    }

    public function store(StoreLabelRequest $request, Labels $labels): JsonResponse
    {
        try {
            $id = $labels->create((string) $request->validated('name'));
        } catch (InvalidArgumentException $error) {
            throw ValidationException::withMessages(['name' => $error->getMessage()]);
        }

        return response()->json(['id' => $id], 201);
    }
}
```

`app/Modules/ReferenceCatalog/routes/web.php`

```php
<?php

use App\Modules\ReferenceCatalog\Presentation\Http\Controllers\LabelController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->prefix('catalog')->name('catalog.')->group(function (): void {
    Route::get('/labels', [LabelController::class, 'index'])->name('labels.index');
    Route::post('/labels', [LabelController::class, 'store'])->name('labels.store');
});
```

`app/Modules/ReferenceCatalog/Infrastructure/Providers/ReferenceCatalogServiceProvider.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Infrastructure\Providers;

use App\Modules\ReferenceCatalog\Application\Api\Labels;
use App\Modules\ReferenceCatalog\Application\Commands\CreateLabelHandler;
use App\Modules\ReferenceCatalog\Application\Contracts\ListLabelsQuery;
use App\Modules\ReferenceCatalog\Application\Contracts\StoresLabels;
use App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\EloquentLabelStore;
use App\Modules\ReferenceCatalog\Infrastructure\Queries\ListLabels;
use Illuminate\Support\ServiceProvider;

class ReferenceCatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Labels::class, CreateLabelHandler::class);
        $this->app->bind(StoresLabels::class, EloquentLabelStore::class);
        $this->app->bind(ListLabelsQuery::class, ListLabels::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    }
}
```

O registro de `ReferenceCatalogServiceProvider::class` já foi acrescentado a `bootstrap/providers.php` pelo gerador. `loadMigrationsFrom` permite usar `migrate` normalmente, sem executar uma migration manualmente por PHP. Preserve os caminhos/basenames históricos dos módulos existentes; Teams e Identity já carregam suas migrations em Infrastructure.

### 6. Seeder explícito e validação

`app/Modules/ReferenceCatalog/Infrastructure/Persistence/Seeders/ReferenceCatalogLabelsSeeder.php`

```php
<?php

namespace App\Modules\ReferenceCatalog\Infrastructure\Persistence\Seeders;

use App\Modules\ReferenceCatalog\Application\Api\Labels;
use Illuminate\Database\Seeder;
use LogicException;

class ReferenceCatalogLabelsSeeder extends Seeder
{
    public function run(Labels $labels): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Demo data may only be seeded in local or testing environments.');
        }

        $labels->create('Example label');
    }
}
```

Não acrescente esse seeder ao `DatabaseSeeder` padrão. Execute a classe FQCN explicitamente no banco descartável:

```bash
php artisan migrate --no-interaction
php artisan db:seed --class='App\Modules\ReferenceCatalog\Infrastructure\Persistence\Seeders\ReferenceCatalogLabelsSeeder' --no-interaction
php artisan test --compact tests/Feature/ReferenceCatalogLabelsTest.php
composer architecture
composer types:check
php artisan route:list --name=catalog.labels --no-interaction
php artisan migrate:status --no-interaction
php artisan wayfinder:generate --with-form --no-interaction
npm run types:check
```

Formate também o registro de provider atualizado pelo gerador antes dos checks completos:

```bash
vendor/bin/pint app/Modules/ReferenceCatalog bootstrap/providers.php tests/Feature/ReferenceCatalogLabelsTest.php --format agent
npm run build
composer ci:check
```

O gate global `COMPOSER_PROCESS_TIMEOUT=0 composer coverage` continua exigindo exatamente 100% de linhas e branches: acrescentar o módulo numa aplicação real também exige completar os testes dos novos ramos. O tutorial demonstra comportamento/arquitetura; seus poucos testes não substituem esse gate completo.

Wayfinder gera imports nomeados a partir das rotas reais, por exemplo:

```ts
import { index, store } from '@/routes/catalog/labels';

index(); // URL e método GET gerados
store(); // URL e método POST gerados
store.form(); // atributos de formulário; exige --with-form
```

O plugin de Vite já gera Wayfinder durante o build. Não versione `resources/js/routes`, `actions` ou `wayfinder` gerados. Novas páginas Inertia seguem os componentes e formulários existentes; o exemplo acima é JSON e não acrescenta uma página Vue.

## Fallbacks nativos e recursos opcionais

Se optar por não usar `make:module-artifact`, os geradores nativos aceitam FQCN para os arquivos abaixo. São **alternativas**, não uma segunda execução sobre os mesmos arquivos:

```bash
php artisan make:model 'App\Modules\ReferenceCatalog\Infrastructure\Persistence\Eloquent\Models\Label' --no-interaction
php artisan make:controller 'App\Modules\ReferenceCatalog\Presentation\Http\Controllers\LabelController' --no-interaction
php artisan make:request 'App\Modules\ReferenceCatalog\Presentation\Http\Requests\StoreLabelRequest' --no-interaction
php artisan make:migration create_doc_example_labels_table --path=app/Modules/ReferenceCatalog/database/migrations --no-interaction
```

O model nativo não recebe a factory modular automaticamente neste fallback: use `make:module-artifact ReferenceCatalog factory Label` após criar o model, ou crie a classe de factory no caminho mostrado e mantenha `newFactory()` explícito. O helper `model --factory` já gera ambos com wiring modular.

Um comando de console também pode ser uma Presentation do módulo:

```bash
php artisan make:module-command ReferenceCatalog ListLabels --no-interaction
php artisan reference-catalog:list-labels --help
```

Esse gerador delega a `make:command`, e o bootstrap descobre `Presentation/Console` por módulo. O corpo gerado permanece pendente: implemente sua chamada ao query/handler antes de executá-lo. Não confunda `make:module-usecase` com um comando CLI ou suponha que o template tenha um `make:usecase` global.

## Evidência e alcance

Os nomes/options acima foram consultados em `artisan list`/`--help`. O exemplo foi aplicado numa cópia separada com banco SQLite próprio: teste inicialmente vermelho, geração nativa, implementação, teste verde, architecture/PHPStan, migrations, seed repetido e Wayfinder/types/build. O exemplo não consulta AWS, não altera módulos distribuídos nem demonstra isolamento por tenant, concorrência complexa ou reversibilidade de migrations de uma aplicação real.
