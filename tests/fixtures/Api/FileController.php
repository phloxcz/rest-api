<?php

declare(strict_types=1);

namespace Phlox\RestApi\Tests\Fixtures\Api;

use Nette\Application\Responses\TextResponse;
use Nette\Http\FileUpload;
use Phlox\ApiCore\ApiController;
use Phlox\ApiCore\Attributes\AllowAnonymous;
use Phlox\RestApi\Attributes\Consumes;
use Phlox\RestApi\Attributes\FromBody;
use Phlox\RestApi\Attributes\FromForm;
use Phlox\RestApi\Attributes\FromHeader;
use Phlox\RestApi\Attributes\HttpGet;
use Phlox\RestApi\Attributes\HttpPost;
use Phlox\RestApi\Attributes\Route;

#[AllowAnonymous]
#[Route('files')]
class FileController extends ApiController
{
    /** Writes the response itself, as a report does with readfile() */
    #[HttpGet]
    #[Route('printed')]
    public function printed(): void
    {
        $this->response->setCode(200);
        print 'printed';
    }

    /** Reads a large upload from php://input itself */
    #[HttpPost]
    #[Route('stream')]
    public function stream(string $name = 'default'): array
    {
        return ['name' => $name];
    }

    /** A list in the body must not fill $mode by its position */
    #[HttpPost]
    #[Route('rows')]
    public function rows(#[FromBody] array $rows, string $mode = 'default'): array
    {
        return ['rows' => $rows, 'mode' => $mode];
    }

    #[HttpPost]
    #[Route('json-array')]
    public function jsonArray(#[FromBody] array $data): array
    {
        return ['type' => get_debug_type($data), 'data' => $data];
    }

    #[HttpPost]
    #[Route('json-object')]
    public function jsonObject(#[FromBody] object $data): array
    {
        return ['type' => get_debug_type($data), 'name' => $data->name ?? null];
    }

    #[HttpPost]
    #[Route('optional')]
    public function optional(#[FromBody] ?array $data = null, string $mode = 'default'): array
    {
        return ['data' => $data, 'mode' => $mode];
    }

    #[HttpPost]
    #[Route('upload')]
    #[Consumes('application/pdf')]
    #[Consumes('image/*')]
    public function upload(#[FromBody] string $content): array
    {
        return ['bytes' => strlen($content), 'content' => $content];
    }

    #[HttpPost]
    #[Route('only-xml')]
    #[Consumes('application/xml')]
    public function onlyXml(): string
    {
        return 'xml accepted';
    }

    #[HttpPost]
    #[Route('no-consumes')]
    public function noConsumes(): array
    {
        return ['jsonData' => $this->jsonData];
    }

    /** Streams the body itself, with only its type checked */
    #[HttpPost]
    #[Route('streamed')]
    #[Consumes('application/octet-stream')]
    public function streamed(string $name = 'default'): array
    {
        return ['name' => $name];
    }

    #[HttpGet]
    #[Route('headers')]
    public function headers(#[FromHeader('X-Request-Id')] ?string $requestId = null, #[FromHeader] int $maxItems = 10): array
    {
        return ['requestId' => $requestId, 'maxItems' => $maxItems];
    }

    #[HttpGet]
    #[Route('if-match')]
    public function ifMatch(#[FromHeader] string $ifMatch): string
    {
        return $ifMatch;
    }

    /**
     * @param list<FileUpload> $attachments
     */
    #[HttpPost]
    #[Route('form')]
    public function form(
        #[FromForm] FileUpload $file,
        #[FromForm] string $title,
        #[FromForm] int $pages = 1,
        #[FromForm] ?FileUpload $preview = null,
        #[FromForm('attachment')] array $attachments = [],
    ): array {
        return [
            'file' => $file->getUntrustedName(),
            'title' => $title,
            'pages' => $pages,
            'preview' => $preview?->getUntrustedName(),
            'attachments' => array_map(static fn(FileUpload $upload): string => $upload->getUntrustedName(), $attachments),
        ];
    }

    #[HttpPost]
    #[Route('fields')]
    public function fields(#[FromForm] string $title): string
    {
        return $title;
    }

    #[HttpGet]
    #[Route('download')]
    public function download(): TextResponse
    {
        return new TextResponse('plain text from an application response');
    }

    #[HttpGet]
    #[Route('tree/{?path:all}')]
    public function tree(string $path = 'root'): string
    {
        return $path;
    }

    #[HttpGet]
    #[Route('a/{?b:int}/c')]
    public function optionalInMiddle(int $b = 0): int
    {
        return $b;
    }

    #[HttpGet]
    #[Route('code/{code:regex}', ['code' => '[A-Z]{3}'])]
    public function code(string $code): string
    {
        return $code;
    }

    #[HttpGet]
    #[Route('name-{name:alpha}.json')]
    public function mixedSegment(string $name): string
    {
        return $name;
    }

    #[HttpGet]
    #[Route('flag/{value:bool}')]
    public function flag(bool $value): bool
    {
        return $value;
    }

    #[HttpGet]
    #[Route('guid/{id:guid}')]
    public function guid(string $id): string
    {
        return $id;
    }

    #[HttpGet]
    #[Route('short/{value:maxlength(3)}')]
    public function short(string $value): string
    {
        return $value;
    }
}
