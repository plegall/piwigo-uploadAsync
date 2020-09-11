#!/usr/bin/perl

####
# Usage
#
# perl piwigo_uploadAsync_chunk.pl --url=http://piwigo.org/demo --user=admin --password=secret --filename=photo.jpg --album_id=9

use strict;
use warnings;

use JSON;
use Data::Dumper;
use LWP::UserAgent;
use Getopt::Long;
use POSIX qw(ceil floor);
use Digest::MD5 qw/md5 md5_hex/;
use File::Slurp;
use File::Basename;
use List::Util qw/shuffle/;

my %opt = ();
GetOptions(
    \%opt,
    qw/
          filename=s
          album_id=i
          category=s
          url=s
          username=s
          password=s
          chunks=i
          chunk=i
          original_sum=s
      /
);

our %conf = (
    chunk_size => $opt{chunk_size},
);

my $result = undef;

my $chunk_path = '/tmp/'.$opt{original_sum}.'-'.$opt{chunk}.'.chunk';

my $ua = LWP::UserAgent->new;
$ua->agent('Mozilla/piwigo_uploadAsync.pl 1.56');
$ua->cookie_jar({});

my $response = $ua->post(
    $opt{url}.'/ws.php?format=json',
    {
        method => 'pwg.images.uploadAsync',
        username => $opt{username},
        password => $opt{password},
        original_sum => $opt{original_sum},
        filename => $opt{filename},
        chunk => $opt{chunk},
        chunks => $opt{chunks},
        category => $opt{album_id},
        file => [$chunk_path],
        name => 'a random title',
        author => 'random author',
        date_creation => '2020-08-20',
        tag_ids => '31,28',
        level => 0,
        comment => 'a random description',
        # image_id => 3985,
    },
    'Content_Type' => 'form-data',
);
printf("response code    : %u\n", $response->code);
print($response->content."\n");
# printf("response content : %s\n", Dumper(from_json($response->content)));

unlink($chunk_path);

printf(
    'chunk %03u of %03u for "%s"'."\n",
    $opt{chunk}+1,
    $opt{chunks},
    $opt{filename}
);

if ($response->code != 200) {
    printf("response code    : %u\n", $response->code);
    printf("response message : %s\n", $response->message);
}