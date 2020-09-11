#!/usr/bin/perl

####
# Usage
#
# perl piwigo_uploadAsync.pl --url=http://piwigo.org/demo --user=admin --password=secret --file=photo.jpg --album_id=9

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
          file=s
          album_id=i
          category=s
          url=s
          username=s
          password=s
      /
);

our %conf = (
    chunk_size => 500_000,
);

my $result = undef;

chomp(my $original_sum = `md5 -q $opt{file}`);
my $content = read_file($opt{file});
my $content_length = length($content);
my $nb_chunks = ceil($content_length / $conf{chunk_size});

my $chunk_pos = 0;
my $chunk_id = 0;
my @chunk_ids = ();

while ($chunk_pos < $content_length) {
    my $chunk = substr(
        $content,
        $chunk_pos,
        $conf{chunk_size}
    );

    # write the chunk as a temporary local file
    my $chunk_path = '/tmp/'.$original_sum.'-'.$chunk_id.'.chunk';

    open(my $ofh, '>'.$chunk_path) or die "problem for writing temporary local chunk";
    print {$ofh} $chunk;
    close($ofh);

    push(@chunk_ids, $chunk_id);
    $chunk_pos += $conf{chunk_size};
    $chunk_id++;
}

my @chunk_ids_rand = shuffle(@chunk_ids);

foreach my $chunk_id (@chunk_ids_rand) {
    my $chunk_path = '/tmp/'.$original_sum.'-'.$chunk_id.'.chunk';

    my $ua = LWP::UserAgent->new;
    $ua->agent('Mozilla/piwigo_uploadAsync.pl 1.56');
    $ua->cookie_jar({});

    my $response = $ua->post(
        $opt{url}.'/ws.php?format=json',
        {
            method => 'pwg.images.uploadAsync',
            username => $opt{username},
            password => $opt{password},
            original_sum => $original_sum,
            filename => basename($opt{file}),
            chunk => $chunk_id,
            chunks => $nb_chunks,
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
    print($response->content);
    printf("response content : %s\n", Dumper(from_json($response->content)));

    unlink($chunk_path);

    printf(
        'chunk %03u of %03u for "%s"'."\n",
        $chunk_id+1,
        $nb_chunks,
        $opt{file}
    );

    if ($response->code != 200) {
        printf("response code    : %u\n", $response->code);
        printf("response message : %s\n", $response->message);
    }

    # exit();
}