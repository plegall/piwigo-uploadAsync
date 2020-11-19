#!/usr/bin/perl

####
# Usage
#
# perl piwigo_uploadAsync_parallel.pl --url=http://piwigo.org/demo --user=admin --password=secret --file=photo.jpg --album_id=9

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
    chunk_size => 1_000_000,
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

my $commands_file = '/tmp/'.$original_sum.'_commandlist.txt';
if (-e $commands_file) {
  unlink $commands_file;
}

foreach my $chunk_id (@chunk_ids_rand) {
    my $command = 'perl /Users/plg/git/Piwigo/plugins/uploadAsync/piwigo_uploadAsync_chunk.pl';
    $command.= ' --url='.$opt{url}.' --user='.$opt{username}.' --password='.$opt{password};
    $command.= ' --filename='.basename($opt{file}).' --album_id='.$opt{album_id};
    $command.= ' --chunks='.$nb_chunks.' --chunk='.$chunk_id.' --original_sum='.$original_sum;

    my $chunk_path = '/tmp/'.$original_sum.'-'.$chunk_id.'.chunk';

    open(my $ofh, '>>'.$commands_file) or die "problem for writing temporary command list";
    print {$ofh} $command."\n";
    close($ofh);
}

my $parallel_command = 'perl /Users/plg/git/Piwigo/plugins/uploadAsync/parallel.pl --listfile='.$commands_file.' --max=5 --verbose';
system($parallel_command);