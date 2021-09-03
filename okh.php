<?php

$title = $_GET['title'];

if ( ! $title ) {
  echo 'Title required';
  exit;
}

$title_match = preg_match('/(?<=https:\/\/www.appropedia.org\/)(.*)/', $title, $matches);
$pageName = $matches[0];

$content = file_get_contents("https://www.appropedia.org/w/api.php?action=query&prop=revisions&titles=" 
. $pageName 
. "&rvslots=*&rvprop=content|timestamp&formatversion=latest&format=json"
);
$json_values = json_decode($content, true);
$timestamp = substr($json_values["query"]["pages"][0]["revisions"][0]["timestamp"], 0, -10); //timestamp

//read description from the API
$extract_raw = file_get_contents("https://www.appropedia.org/w/api.php?action=query&prop=extracts&exsentences=5&exlimit=1&titles=" 
. $pageName 
. "&formatversion=latest&explaintext=1&format=json"
);
$json_extract = json_decode($extract_raw, true);
$extract = trim(str_replace("\n", " ", $json_extract["query"]["pages"][0]["extract"]), 200);

// Metadata
$json_metadatatemplates = file_get_contents("https://www.appropedia.org/w/api.php?action=query&title="
. $pageName 
. "&action=expandtemplates&text={{FIRSTREVISIONUSER}}--{{FULLPAGENAME}}--{{PAGELANGUAGE}}--{{FIRSTREVISIONTIMESTAMP}}--{{REALNAME:{{FIRSTREVISIONUSER}}}}&format=json"
);
$metadata = json_decode($json_metadatatemplates, true);
$metadata = $metadata["expandtemplates"]["*"];
$metadata = explode("--", $metadata);
//processing metadata
$dateCreated = preg_replace("/^(\d{4})(\d{2})(\d{2})$/", "$1-$2-$3",  substr($metadata[3], 0, 8));
$language = $metadata[2];

// Images
$image = file_get_contents("https://www.appropedia.org/w/api.php?action=query&titles="
. $pageName 
. "&formatversion=latest&prop=pageimages&format=json&pithumbsize=1000"
);
$image = json_decode($image, true);
$image = $image["query"]["pages"][0]["thumbnail"]["source"];

// Revisions
$version = file_get_contents("https://www.appropedia.org/w/api.php?action=query&titles="
. $pageName
. "&prop=revisions&rvprop=ids|userid&rvlimit=max&format=json"
);
$version = json_decode($version, true);
$version = count(array_values($version["query"]["pages"])[0]["revisions"]);

//extract databox information
preg_match_all('/{{(Page|Project|Device|Location) data([^\}\}]*)}}/i', $content, $matches, PREG_PATTERN_ORDER);
$matches = implode(" ", $matches[0]);

//extract from databox fields with regex
function extractPattern($keyword, $matches){
  $keyword = trim($keyword);
  $regexPattern = "/(?<=".$keyword."=)|(?<=".$keyword." =).*?(?=[\\\]n)/";
  if ( preg_match($regexPattern, $matches, $match) ) {
    $result = $match[0];
  } else {
    $result = null;
  };
  return trim($result);
}

$uses = extractPattern('uses', $matches);
$keywords = str_replace(",", "\n  -", extractPattern('keywords', $matches));
$authors = extractPattern('authors', $matches);
$status = trim(end(explode(",", extractPattern('status', $matches))));
$made = strtolower(extractPattern('made', $matches))=='yes' ? 'TRUE' : 'FALSE';
$instanceOf = extractPattern('instance-of', $matches);
$license = extractPattern('license', $matches);
$sdg = extractPattern('sdg', $matches);

// get all contributors' names
$publishedBy = str_replace("User:", "", extractPattern('published-by', $matches));
if (isset($publishedBy)) {
  $publishers = "{{REALNAME:" . str_replace(",", "}}--{{REALNAME:", $publishedBy) . "}}";
  $json_publishers = file_get_contents("https://www.appropedia.org/w/api.php?action=query&action=expandtemplates&text="
  . $publishers
  ."&prop=properties&format=json"
  );
  $publishers = json_decode($json_publishers, true);
  $publishers = $metadata["expandtemplates"]["wikitext"];
  $publishers = str_replace("--", ", ", $metadata);
}

$usernameCreated = $metadata[0];
$nameCreated = $metadata[4];

//forming the YAML file
header ('Content-Type: application/x-yaml');
header('Content-Disposition: attachment;filename="export.yaml"');
echo(
"# Open know-how manifest 1.0
# The content of this manifest file is licensed under a Creative Commons Attribution 4.0 International License. 
# Licenses for modification and distribution of the hardware, documentation, source-code, etc are stated separately.

# Manifest metadata\n"
. "date-created: " . $dateCreated . "\n"
. "date-updated: " . $timestamp . "\n"
. "manifest-author:"
  . "\n  name: OKH Bot"
  . "\n  affiliation: Appropedia"
  . "\n  email: support@appropedia.org" . "\n"
. "documentation-language: en"   
. "\ndocumentation-language: " 
  . ($language != "" ? $language : "en") . "\n"

. "\n#Properties\n"
. "title: " . $pageName . "\n"
. ($extract != "" ? ("description: >-\n  " . $extract . "\n") : "")
. ($uses ? ("intended-use: " . $uses . "\n") : "")
. ($keywords != "" ? ("keywords: \n  - " . $keywords . "\n") : "")
. "project-link: https://www.appropedia.org/" 
  . str_replace(" ", "_", $pageName) . "\n"
. "contact:"
  . "\n  name: " . $nameCreated
  . "\n  social:\n  - platform: Appropedia\n    user-handle: " . $usernameCreated . "\n"
. ($location != "" ? ("location: " . $location . "\n") : "")
. ($image != "" ? ("image: " . $image . "\n") : "")
. ($version != "" ? ("version: " . $version . "\n") : "")
. ($status != "" ? ("development-stage: " . $status . "\n") : "")
. ($made != "" ? ("made: " . $made . "\n") : "")
. ($instanceOf != "" ? ("variant-of: "
  . "\n  name: " . $instanceOf
  . "\n  web: https://www.appropedia.org/" . str_replace(" ", "_", $instanceOf)
  . "\n" ) : "")
. ($license != "" && $license!="" ? 
  ("\n#License\nlicense: " 
  . "\n  documentation: " . $license
  . "\n" ) : ("license:\n  documentation: CC BY-SA 4.0\n")
  )
. "licensor:"
  . "\n  name: " . $nameCreated
  . ($affiliations != "" ? ("affiliation: " . $affiliations . "\n") : "")
  . "\n  contact: " . "https://www.appropedia.org/" . str_replace(" ", "_", $usernameCreated)
  . "\ndocumentation-home: " . "https://www.appropedia.org/" . str_replace(" ", "_", $pageName) . "\n"
  . "\n#User-defined fields\n"
  . ($sdg != "" ? ("sustainable-development-goals: " . $sdg . "\n") : "")
);
