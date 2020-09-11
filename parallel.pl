#!/usr/bin/perl -w

use strict;
use Getopt::Long;
use  File::Basename;

# perform flush after each write to STDOUT. If forgotten, may cause many
# problems in redirections with parallel sub-process.
$| = 1;

my %opt = ();
GetOptions(\%opt, qw/max=i listfile=s version help test verbose/);

if (defined($opt{version}))
{
  print basename($0).' '.('Revision: 1.3 ' =~ /: ([^ ]+)/)[0]."\n";
  exit(0);
}

if (defined($opt{help}))
{
  print <<FIN;

Execute shell commands in parallel, given a maximum number of parallel
executions.

Usage: execute_sql.pl --max=<i>
                      --listfile=<shell commands file>
                      --test
                      [--version]

--max : maximum number of executions in parallel

--listfile=<shell commands file> : given file must contain one shell command
  per line

--test : creates a random number of sleep command with random durations

--version : shows this script revision

FIN

  exit(0);
}

my $usage = "\n\n".basename($0)." --help for help\n\n";

foreach ('max')
{
  die 'Error: option --'.$_.' is mandatory', $usage if not defined $opt{$_};
}

if (not defined($opt{listfile}) and not defined($opt{test}))
{
  die 'Error: choose between --listfile and --test', $usage;
}

my @commands;

if (defined($opt{listfile}))
{
  if (not -r $opt{listfile})
  {
    die 'Error: file '.$opt{listfile}.' cannot be read', $usage;
  }

  open(LIST, '< '.$opt{listfile});
  while (<LIST>)
  {
    chomp;
    s/\r$//;
    next if /^$/;
    push @commands, $_;
  }
  close(LIST);
}
else # $opt{test} is set
{
  @commands = map 'echo '.$_.' > /dev/null; sleep '.(int(rand 20) + 0), (1..(int(rand 20) + 10));
}

my %running_pids;

sub wait_for_a_kid
{
  my $pid = wait;
  return 0 if $pid < 0;
  print '(', get_formatted_date(), ') [', $running_pids{$pid}, '] is finished', "\n" if $opt{verbose};
  1;
}

sub launch_command
{
  my $command = shift;
  system $command;
  1;
}

for (@commands)
{
  wait_for_a_kid() if keys %running_pids >= $opt{max};
  
  if (my $pid = fork)
  {
    # parent does...
    $running_pids{$pid} = $_;
    print '(', get_formatted_date(), ') [', $running_pids{$pid}, '] launched', "\n" if $opt{verbose};
  }
  else
  {
    # child does...
    exit(!launch_command($_));
  }
}

1 while wait_for_a_kid();

print '(', get_formatted_date(), ') done', "\n" if $opt{verbose};

sub get_formatted_date
{
  my ($second, $minute, $hour, $day, $month, $year) = localtime(time);
  my $date = sprintf("%04d.%02d.%02d %02d:%02d:%02d",
                     $year+1900,
                     ($month+1),
                     $day,
                     $hour,
                     $minute,
                     $second);
  return $date;
}
