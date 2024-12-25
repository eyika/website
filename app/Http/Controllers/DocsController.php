<?php

namespace App\Http\Controllers;

use App\Helpers\CustomParsedown;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Arr;

class DocsController extends Controller
{
    public function generatePage(Request $request, $resource = 'docs', $version = 'beta', $page1 = 'index', $page2 = '') {
        logger()->info('got here ******');
        if ($resource !== 'docs') {
            $page2 = $version == 'beta' ? '' : $version;
            $version = 'beta';
            $page1 = $resource;
        }
        $_page2 = empty($page2) ? "" : "/$page2";
        $filePath = base_path("app/docs/$version/$page1{$_page2}.md");

        $navig = config('navigation');
        $versions = [];
        foreach ($navig as $key => $value) {
            array_push($versions, $key);
        }
    
        if (!file_exists($filePath)) {
            http_response_code(404);
            return response()->view('docs.template', [
                'title' => '(Atom) 404 - Page Not Found',
                'version' => $version,
                'versions' => $versions,
                'page' => $page1.$page2,
                'navigation' => config("navigation.$version"),
                'content' => '<h2>Page not found</h2><p>The requested documentation page does not exist.</p>',
                'previousPageUrl' => '',
                'nextPageUrl' => null,
            ]);
        }
    
        $markdown = file_get_contents($filePath);;
        $content = (new CustomParsedown())->text($markdown);
        $navigation = $this->generatePaginationButtons($version, $page1, $page2);
    
        return response()->view('docs.template', [
            'title' => '(Atom) '. ucfirst(str_replace('-', ' ', $page2 ? $page2 : $page1)),
            'version' => $version,
            'versions' => $versions,
            'page' => $page1.$page2,
            'navigation' => config("navigation.$version"),
            'content' => $content,
            'previousPageUrl' => $navigation['previousPageUrl'],
            'nextPageUrl' => $navigation['nextPageUrl'],
        ]);
    }

    private function generatePaginationButtons($version, $page1, $page2) {
        $previousPageUrl = '';
        $nextPageUrl = '';

        $previousPage = Arr::previousItem(config("navigation.$version"), $page2 ? "$page1.$page2" : $page1, true);
        $previousPage = $previousPage ? str_replace('.', '/', $previousPage) : null;

        $nextPage = Arr::nextItem(config("navigation.$version"), $page2 ? "$page1.$page2" : $page1, true);
        $nextPage = $nextPage ? str_replace('.', '/', $nextPage) : null;
    
        $previousPageUrl = $previousPage ? "/docs/$version/$previousPage" : null;
        $nextPageUrl = $nextPage ? "/docs/$version/$nextPage" : null;
    
        return [
            'previousPageUrl' => $previousPageUrl,
            'nextPageUrl' => $nextPageUrl,
        ];
    }
}
