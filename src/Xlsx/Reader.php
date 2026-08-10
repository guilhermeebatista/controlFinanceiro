<?php
/**
 * Leitor de .xlsx/.xlsm — substitui o openpyxl sem dependência externa.
 *
 * Um arquivo Office Open XML é um ZIP de XMLs. Este leitor abre só o que
 * interessa (workbook, relacionamentos, sharedStrings, styles e as planilhas
 * pedidas) e devolve cada linha como um array indexado por coluna, com o
 * índice 0 = coluna A — o mesmo formato de `iter_rows(values_only=True)`, para
 * que o mapeamento de colunas do importador continue valendo.
 *
 * Equivale ao `data_only=True`: lê o <v> da célula, que é o último valor
 * calculado da fórmula, nunca a fórmula em si. Nenhum conteúdo do arquivo é
 * interpretado como código — macros de um .xlsm ficam intocadas dentro do ZIP.
 */

declare(strict_types=1);

namespace MinhasContas\Xlsx;

use MinhasContas\Config;
use MinhasContas\Http;
use XMLReader;
use ZipArchive;

final class Reader
{
    /** Formatos numéricos embutidos que representam data ou hora. */
    private const FMT_DATA_EMBUTIDOS = [
        14, 15, 16, 17, 18, 19, 20, 21, 22,
        27, 28, 29, 30, 31, 32, 33, 34, 35, 36,
        45, 46, 47,
        50, 51, 52, 53, 54, 55, 56, 57, 58,
    ];

    /** Teto de linhas por aba — evita que um arquivo forjado consuma a memória toda. */
    private const MAX_LINHAS = 200_000;

    private ZipArchive $zip;
    private string $caminho;

    /** @var list<string> */
    private array $strings = [];

    /** @var array<int, bool> índice do estilo -> é data? */
    private array $estiloEhData = [];

    /** @var array<string, string> nome da aba -> caminho do XML dentro do ZIP */
    private array $abas = [];

    private bool $epoch1904 = false;

    public function __construct(string $caminho)
    {
        $this->caminho = $caminho;
        $this->zip = new ZipArchive();

        if ($this->zip->open($caminho, ZipArchive::RDONLY) !== true) {
            Http::erro(400, 'Não foi possível ler o arquivo. Envie um .xlsx ou .xlsm válido.');
        }

        $this->recusarZipBomb();
        $this->carregarAbas();
        $this->carregarStrings();
        $this->carregarEstilos();
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    /** @return list<string> */
    public function nomesDasAbas(): array
    {
        return array_keys($this->abas);
    }

    public function temAba(string $nome): bool
    {
        return isset($this->abas[$nome]);
    }

    /**
     * Percorre a aba devolvendo uma linha por vez.
     *
     * Generator em vez de array: uma planilha de anos de lançamentos não
     * precisa caber inteira na memória para ser importada.
     *
     * @return \Generator<int, list<mixed>> número da linha (1-based) => valores
     */
    public function linhas(string $aba): \Generator
    {
        if (!isset($this->abas[$aba])) {
            return;
        }

        $xml = new XMLReader();
        // zip:// lê a entrada em fluxo, sem materializar o XML descomprimido.
        if (@$xml->open('zip://' . $this->caminho . '#' . $this->abas[$aba]) === false) {
            return;
        }

        // expand() precisa de um documento de destino: sem ele o nó volta sem
        // Document associado e simplexml_import_dom() recusa. O mesmo
        // DOMDocument é reaproveitado em todas as linhas.
        $doc = new \DOMDocument();
        $lidas = 0;
        try {
            while ($xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->name !== 'row') {
                    continue;
                }
                if (++$lidas > self::MAX_LINHAS) {
                    break;
                }
                $numero = (int) ($xml->getAttribute('r') ?? $lidas);
                $no = $xml->expand($doc);
                if (!$no instanceof \DOMNode) {
                    continue;
                }
                $linha = simplexml_import_dom($no);
                if ($linha === null) {
                    continue;
                }
                yield $numero => $this->lerLinha($linha);
            }
        } finally {
            $xml->close();
        }
    }

    // ------------------------------------------------------------ internos

    /**
     * Um ZIP de poucos KB pode declarar gigabytes descomprimidos ("zip bomb").
     * Como a soma vem do índice do próprio arquivo, dá para recusar antes de
     * descomprimir qualquer coisa.
     */
    private function recusarZipBomb(): void
    {
        $total = 0;
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $st = $this->zip->statIndex($i);
            if ($st === false) {
                continue;
            }
            $total += (int) $st['size'];
            if ($total > Config::XLSX_MAX_DESCOMPRIMIDO) {
                Http::erro(400, 'Planilha grande demais para ser processada.');
            }
        }
    }

    private function conteudo(string $entrada): ?string
    {
        $dados = $this->zip->getFromName($entrada);
        return $dados === false ? null : $dados;
    }

    private function xml(string $entrada): ?\SimpleXMLElement
    {
        $bruto = $this->conteudo($entrada);
        if ($bruto === null || $bruto === '') {
            return null;
        }
        // LIBXML_NONET + a ausência de LIBXML_NOENT mantêm entidades externas
        // desligadas: um XXE plantado na planilha não busca arquivo local nem
        // faz requisição de rede.
        $doc = @simplexml_load_string($bruto, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        return $doc === false ? null : $doc;
    }

    /** Resolve nome da aba -> arquivo XML, passando pelos relacionamentos. */
    private function carregarAbas(): void
    {
        $wb = $this->xml('xl/workbook.xml');
        if ($wb === null) {
            Http::erro(400, 'Não foi possível ler o arquivo. Envie um .xlsx ou .xlsm válido.');
        }

        $pr = $wb->workbookPr ?? null;
        if ($pr !== null && (string) ($pr['date1904'] ?? '') !== '') {
            $this->epoch1904 = in_array((string) $pr['date1904'], ['1', 'true'], true);
        }

        $alvos = [];
        $rels = $this->xml('xl/_rels/workbook.xml.rels');
        if ($rels !== null) {
            foreach ($rels->Relationship as $rel) {
                $alvo = (string) $rel['Target'];
                // Alvos vêm como "worksheets/sheet1.xml" ou "/xl/worksheets/sheet1.xml".
                $alvo = ltrim(str_replace('\\', '/', $alvo), '/');
                if (!str_starts_with($alvo, 'xl/')) {
                    $alvo = 'xl/' . $alvo;
                }
                $alvos[(string) $rel['Id']] = $alvo;
            }
        }

        $indice = 1;
        foreach ($wb->sheets->sheet ?? [] as $aba) {
            $nome = (string) $aba['name'];
            $rid  = (string) $aba->attributes('r', true)->id;
            $this->abas[$nome] = $alvos[$rid] ?? "xl/worksheets/sheet{$indice}.xml";
            $indice++;
        }
    }

    /** Tabela de strings compartilhadas — o alvo de `t="s"`. */
    private function carregarStrings(): void
    {
        $bruto = $this->conteudo('xl/sharedStrings.xml');
        if ($bruto === null || $bruto === '') {
            return;
        }

        $xml = new XMLReader();
        if (@$xml->XML($bruto, null, LIBXML_NONET) === false) {
            return;
        }
        $doc = new \DOMDocument();
        try {
            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->name === 'si') {
                    $no = $xml->expand($doc);
                    // <si> pode ter vários <t> (texto com formatação mista);
                    // textContent concatena todos, que é o texto visível.
                    $this->strings[] = $no instanceof \DOMNode ? (string) $no->textContent : '';
                }
            }
        } finally {
            $xml->close();
        }
    }

    /**
     * Marca quais índices de estilo representam data.
     *
     * No XLSX uma data é só um número; o que a distingue é o formato aplicado
     * pela célula. Sem consultar os estilos, 09/07/2026 chegaria como 46212.
     */
    private function carregarEstilos(): void
    {
        $st = $this->xml('xl/styles.xml');
        if ($st === null) {
            return;
        }

        $personalizados = [];
        foreach ($st->numFmts->numFmt ?? [] as $fmt) {
            $id     = (int) $fmt['numFmtId'];
            $codigo = (string) $fmt['formatCode'];
            $personalizados[$id] = self::formatoParecerData($codigo);
        }

        $i = 0;
        foreach ($st->cellXfs->xf ?? [] as $xf) {
            $id = (int) ($xf['numFmtId'] ?? 0);
            $this->estiloEhData[$i++] = $personalizados[$id]
                ?? in_array($id, self::FMT_DATA_EMBUTIDOS, true);
        }
    }

    /** Um formato personalizado é data se tiver tokens de data fora das aspas. */
    private static function formatoParecerData(string $codigo): bool
    {
        $semLiterais = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./u', '', $codigo) ?? $codigo;
        return preg_match('/[dmyhs]/i', $semLiterais) === 1;
    }

    /**
     * @return list<mixed>
     */
    private function lerLinha(\SimpleXMLElement $linha): array
    {
        $valores = [];
        foreach ($linha->c as $celula) {
            $ref = (string) $celula['r'];
            $col = self::indiceDaColuna($ref);
            $valores[$col] = $this->lerCelula($celula);
        }
        if ($valores === []) {
            return [];
        }
        // Preenche os buracos: colunas vazias precisam existir como null para
        // que o índice de cada coluna continue batendo.
        $largura = max(array_keys($valores)) + 1;
        $linhaCompleta = array_fill(0, $largura, null);
        foreach ($valores as $i => $v) {
            $linhaCompleta[$i] = $v;
        }
        return $linhaCompleta;
    }

    private function lerCelula(\SimpleXMLElement $c): mixed
    {
        $tipo = (string) ($c['t'] ?? 'n');

        if ($tipo === 'inlineStr') {
            return isset($c->is) ? trim((string) $c->is->t) : null;
        }

        $bruto = isset($c->v) ? (string) $c->v : null;
        if ($bruto === null || $bruto === '') {
            return null;
        }

        return match ($tipo) {
            's'          => $this->strings[(int) $bruto] ?? null,
            'str'        => $bruto,           // resultado textual de fórmula
            'b'          => $bruto === '1',
            'e'          => null,             // #N/A, #DIV/0! ...
            default      => $this->lerNumero($c, $bruto),
        };
    }

    /** Numérico puro, ou data quando o estilo da célula diz que é data. */
    private function lerNumero(\SimpleXMLElement $c, string $bruto): mixed
    {
        if (!is_numeric($bruto)) {
            return $bruto;
        }
        $n = (float) $bruto;

        $estilo = isset($c['s']) ? (int) $c['s'] : 0;
        if (($this->estiloEhData[$estilo] ?? false) && $n > 0) {
            return $this->serialParaData($n);
        }

        // Devolve int quando o valor é inteiro: o importador testa
        // is_int/is_float para decidir se a célula é um ano ou um valor.
        return ($n === floor($n) && abs($n) < 1e15) ? (int) $n : $n;
    }

    /**
     * Serial do Excel -> 'YYYY-MM-DD'.
     *
     * A base é 30/12/1899 e não 01/01/1900 porque o Excel trata 1900 como
     * bissexto (compatibilidade com o Lotus 1-2-3); o deslocamento de dois
     * dias cancela esse erro.
     */
    private function serialParaData(float $serial): ?string
    {
        $base = $this->epoch1904 ? '1904-01-01' : '1899-12-30';
        $dias = (int) floor($serial);
        if ($dias < 1 || $dias > 400_000) {
            return null;
        }
        $data = (new \DateTimeImmutable($base, new \DateTimeZone('UTC')))
            ->modify("+{$dias} days");
        return $data === false ? null : $data->format('Y-m-d');
    }

    /** "BC12" -> 54 (A = 0). */
    private static function indiceDaColuna(string $ref): int
    {
        $indice = 0;
        $n = strlen($ref);
        for ($i = 0; $i < $n; $i++) {
            $ch = strtoupper($ref[$i]);
            if ($ch < 'A' || $ch > 'Z') {
                break;
            }
            $indice = $indice * 26 + (ord($ch) - 64);
        }
        return max(0, $indice - 1);
    }
}
